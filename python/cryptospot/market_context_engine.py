import json
from datetime import datetime, timedelta
from decimal import Decimal
from typing import Any

from cryptospot.db import fetch_all, fetch_one, get_connection
from cryptospot.health import write_health_log
from cryptospot.logger import get_logger

SERVICE_NAME = "market_context_engine"
TIMEFRAME_LIMITS = {
    "1m": 300,
    "5m": 300,
    "15m": 300,
    "1h": 200,
}

logger = get_logger(__name__)


def safe_float(value: Any, default=None):
    if value is None or value == "":
        return default
    try:
        return float(value)
    except (TypeError, ValueError):
        return default


def json_default(value: Any):
    if isinstance(value, Decimal):
        return float(value)
    if isinstance(value, datetime):
        return value.strftime("%Y-%m-%d %H:%M:%S")
    return str(value)


def calculate_change_percent(candles: list, minutes_back: int) -> float | None:
    if not candles or len(candles) < 2:
        return None

    latest = candles[-1]
    latest_close = safe_float(latest.get("close"))
    latest_time = latest.get("candle_time")
    if latest_close is None or latest_time is None:
        return None

    target_time = latest_time - timedelta(minutes=minutes_back)
    oldest_time = candles[0].get("candle_time")
    if oldest_time is None or oldest_time > target_time:
        return None

    old = min(candles[:-1], key=lambda row: abs(row["candle_time"] - target_time), default=None)
    old_close = safe_float(old.get("close")) if old else None
    if old_close is None or old_close <= 0:
        return None

    return ((latest_close - old_close) / old_close) * 100


def classify_market_condition(btc_changes: dict, eth_changes: dict) -> str:
    btc_1h = btc_changes.get("change_1h_percent")
    btc_4h = btc_changes.get("change_4h_percent")
    btc_24h = btc_changes.get("change_24h_percent")

    if btc_24h is None:
        return "unknown"

    if (
        (btc_1h is not None and btc_1h <= -2)
        or (btc_4h is not None and btc_4h <= -4)
        or btc_24h <= -6
    ):
        return "bearish"

    if btc_1h is not None and btc_4h is not None and btc_1h >= 2 and btc_4h >= 3:
        return "bullish"

    if (
        (btc_1h is not None and abs(btc_1h) >= 4)
        or (btc_4h is not None and abs(btc_4h) >= 7)
    ):
        return "volatile"

    if btc_24h >= 2:
        return "bullish"

    if btc_24h <= -2:
        return "bearish"

    return "neutral"


class MarketContextEngine:
    def run(self, source: str = "manual", scan_run_id: int = None) -> dict:
        summary = {
            "market_snapshot_id": None,
            "btc_symbol": None,
            "eth_symbol": None,
            "btc_price": None,
            "eth_price": None,
            "market_condition": None,
            "snapshot_inserted": False,
            "errors": [],
        }

        try:
            btc_symbol = self._resolve_symbol("BTC")
            eth_symbol = self._resolve_symbol("ETH")
            if not btc_symbol:
                summary["errors"].append("BTC active spot symbol not found for USDT or INR quote")
            if not eth_symbol:
                summary["errors"].append("ETH active spot symbol not found for USDT or INR quote")

            btc_context = self._build_asset_context("BTC", btc_symbol, summary)
            eth_context = self._build_asset_context("ETH", eth_symbol, summary)

            summary["btc_symbol"] = btc_symbol.get("coindcx_symbol") if btc_symbol else None
            summary["eth_symbol"] = eth_symbol.get("coindcx_symbol") if eth_symbol else None
            summary["btc_price"] = btc_context["price"]
            summary["eth_price"] = eth_context["price"]

            if not btc_symbol and not eth_symbol:
                summary["market_condition"] = "unknown"
                write_health_log(SERVICE_NAME, "error", "Market context failed: BTC and ETH symbols were not resolved", summary)
                return summary

            if btc_context["price"] is None and eth_context["price"] is None:
                summary["market_condition"] = "unknown"
                write_health_log(SERVICE_NAME, "error", "Market context failed: no BTC or ETH candles were available", summary)
                return summary

            market_condition = classify_market_condition(btc_context["changes"], eth_context["changes"])
            summary["market_condition"] = market_condition

            reason = self._classification_reason(btc_context["changes"], market_condition)
            market_snapshot_id = self._insert_snapshot(btc_symbol, eth_symbol, btc_context, eth_context, market_condition, reason, source, scan_run_id)
            summary["market_snapshot_id"] = market_snapshot_id
            summary["snapshot_inserted"] = bool(market_snapshot_id)

            missing_changes = self._missing_change_labels("BTC", btc_context) + self._missing_change_labels("ETH", eth_context)
            summary["errors"].extend(missing_changes)

            status = "warning" if summary["errors"] else "ok"
            message = (
                f"Market context completed: condition={market_condition}, "
                f"btc_resolved={bool(btc_symbol)}, eth_resolved={bool(eth_symbol)}, warnings={len(summary['errors'])}"
            )
            write_health_log(SERVICE_NAME, status, message, summary)
            return summary
        except Exception as exc:
            summary["errors"].append(str(exc))
            logger.exception("Market context engine failed")
            write_health_log(SERVICE_NAME, "error", str(exc), summary)
            raise

    def _resolve_symbol(self, base_asset: str) -> dict | None:
        for quote_asset in ("USDT", "INR"):
            row = fetch_one(
                """
                SELECT id, coindcx_symbol, api_pair, base_asset, quote_asset
                FROM spot_symbols
                WHERE is_active = 1 AND base_asset = %s AND quote_asset = %s
                ORDER BY coindcx_symbol ASC
                LIMIT 1
                """,
                (base_asset, quote_asset),
            )
            if row:
                return row
        return None

    def _build_asset_context(self, asset: str, symbol: dict | None, summary: dict) -> dict:
        context = {
            "price": None,
            "changes": {
                "change_5m_percent": None,
                "change_15m_percent": None,
                "change_1h_percent": None,
                "change_4h_percent": None,
                "change_24h_percent": None,
            },
            "candle_counts": {},
            "timeframes_used": {},
        }
        if not symbol:
            return context

        candles = {timeframe: self._load_candles(symbol["id"], timeframe, limit) for timeframe, limit in TIMEFRAME_LIMITS.items()}
        context["candle_counts"] = {timeframe: len(rows) for timeframe, rows in candles.items()}
        latest_candle = self._latest_candle(candles)
        context["price"] = safe_float(latest_candle.get("close")) if latest_candle else None
        if not latest_candle:
            summary["errors"].append(f"{asset} candles not found for {symbol.get('coindcx_symbol')}")
            return context

        calculations = {
            "change_5m_percent": (5, ["1m", "5m"]),
            "change_15m_percent": (15, ["1m", "5m"]),
            "change_1h_percent": (60, ["15m", "5m", "1h"]),
            "change_4h_percent": (240, ["1h", "15m"]),
            "change_24h_percent": (1440, ["1h", "15m"]),
        }
        for key, (minutes, preferred_timeframes) in calculations.items():
            value, timeframe = self._first_change(candles, minutes, preferred_timeframes)
            context["changes"][key] = value
            context["timeframes_used"][key] = timeframe
        return context

    def _load_candles(self, spot_symbol_id: int, timeframe: str, limit: int) -> list[dict]:
        rows = fetch_all(
            """
            SELECT candle_time, open, high, low, close, volume
            FROM candles
            WHERE spot_symbol_id = %s AND timeframe = %s
            ORDER BY candle_time DESC
            LIMIT %s
            """,
            (spot_symbol_id, timeframe, limit),
        )
        return sorted(rows, key=lambda row: row["candle_time"])

    def _latest_candle(self, candles: dict[str, list[dict]]) -> dict | None:
        all_rows = [row for rows in candles.values() for row in rows]
        return max(all_rows, key=lambda row: row["candle_time"]) if all_rows else None

    def _first_change(self, candles: dict[str, list[dict]], minutes_back: int, timeframes: list[str]):
        for timeframe in timeframes:
            value = calculate_change_percent(candles.get(timeframe) or [], minutes_back)
            if value is not None:
                return value, timeframe
        return None, None

    def _missing_change_labels(self, asset: str, context: dict) -> list[str]:
        if context["price"] is None:
            return []
        labels = []
        for key, value in context["changes"].items():
            if value is None:
                labels.append(f"{asset} {key} could not be calculated")
        return labels

    def _classification_reason(self, btc_changes: dict, market_condition: str) -> str:
        return (
            f"BTC-driven classification={market_condition}; "
            f"1h={btc_changes.get('change_1h_percent')}, "
            f"4h={btc_changes.get('change_4h_percent')}, "
            f"24h={btc_changes.get('change_24h_percent')}"
        )

    def _notes(self, btc_symbol: dict | None, eth_symbol: dict | None, scan_run_id: int = None) -> str:
        primary = btc_symbol.get("coindcx_symbol") if btc_symbol else "unresolved BTC"
        notes = [f"BTC/ETH candle-based market context one-shot run. Primary driver: {primary}."]
        if scan_run_id is not None:
            notes.append(f"BTC/ETH market context generated for scan_run_id {scan_run_id}.")
        if btc_symbol and btc_symbol.get("quote_asset") != "USDT":
            notes.append(f"BTC fallback used: {btc_symbol.get('coindcx_symbol')}.")
        if eth_symbol and eth_symbol.get("quote_asset") != "USDT":
            notes.append(f"ETH fallback used: {eth_symbol.get('coindcx_symbol')}.")
        return " ".join(notes)

    def _insert_snapshot(self, btc_symbol, eth_symbol, btc_context, eth_context, market_condition, classification_reason, source: str = "manual", scan_run_id: int = None):
        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        btc_changes = btc_context["changes"]
        eth_changes = eth_context["changes"]
        raw_payload = {
            "btc_symbol": btc_symbol.get("coindcx_symbol") if btc_symbol else None,
            "eth_symbol": eth_symbol.get("coindcx_symbol") if eth_symbol else None,
            "btc_quote_asset": btc_symbol.get("quote_asset") if btc_symbol else None,
            "eth_quote_asset": eth_symbol.get("quote_asset") if eth_symbol else None,
            "candle_counts_used": {
                "BTC": btc_context["candle_counts"],
                "ETH": eth_context["candle_counts"],
            },
            "timeframes_used": {
                "BTC": btc_context["timeframes_used"],
                "ETH": eth_context["timeframes_used"],
            },
            "changes_calculated": {
                "BTC": btc_changes,
                "ETH": eth_changes,
            },
            "classification_reason": classification_reason,
            "source": source,
            "scan_run_id": scan_run_id,
        }

        connection = get_connection()
        cursor = None
        try:
            cursor = connection.cursor()
            cursor.execute(
                """
                INSERT INTO market_snapshots
                (snapshot_time, btc_price, eth_price,
                 btc_change_5m_percent, btc_change_15m_percent, btc_change_1h_percent, btc_change_4h_percent, btc_change_24h_percent,
                 eth_change_5m_percent, eth_change_15m_percent, eth_change_1h_percent, eth_change_4h_percent, eth_change_24h_percent,
                 market_condition, notes, raw_payload, created_at, updated_at)
                VALUES
                    (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                """,
                (
                    now, btc_context["price"], eth_context["price"],
                    btc_changes["change_5m_percent"], btc_changes["change_15m_percent"], btc_changes["change_1h_percent"],
                    btc_changes["change_4h_percent"], btc_changes["change_24h_percent"],
                    eth_changes["change_5m_percent"], eth_changes["change_15m_percent"], eth_changes["change_1h_percent"],
                    eth_changes["change_4h_percent"], eth_changes["change_24h_percent"],
                    market_condition, self._notes(btc_symbol, eth_symbol, scan_run_id),
                    json.dumps(raw_payload, separators=(",", ":"), default=json_default), now, now,
                ),
            )
            connection.commit()
            return cursor.lastrowid
        finally:
            if cursor:
                cursor.close()
            connection.close()
