import json
import sys
from pathlib import Path
from datetime import datetime
from typing import Any

# Allow this module to be run directly from the python/ directory, e.g.
# `python cryptospot/ticker_snapshot_collector.py`, as well as imported by
# scripts/run_ticker_snapshot_once.py.
PYTHON_ROOT = Path(__file__).resolve().parents[1]
if str(PYTHON_ROOT) not in sys.path:
    sys.path.insert(0, str(PYTHON_ROOT))

from cryptospot.coindcx_client import CoinDCXPublicClient
from cryptospot.db import execute, execute_many, fetch_all
from cryptospot.health import write_health_log

SERVICE_NAME = "ticker_snapshot_collector"


def normalize_symbol(value: str) -> str:
    if value is None:
        return ""

    return (
        str(value)
        .strip()
        .upper()
        .replace("/", "")
        .replace("_", "")
        .replace("-", "")
    )


def safe_float(value: Any, default=None):
    if value is None or value == "":
        return default

    try:
        return float(str(value).replace(",", "").strip())
    except (TypeError, ValueError):
        return default


def extract_ticker_symbol(row: dict) -> str | None:
    if not isinstance(row, dict):
        return None

    for key in ("market", "symbol", "pair", "coindcx_name"):
        symbol = normalize_symbol(row.get(key))
        if symbol:
            return symbol

    return None


def normalize_ticker_row(row: dict) -> dict:
    symbol = extract_ticker_symbol(row)
    last_price = _first_float(row, ("last_price", "last", "price", "close"))
    bid_price = _first_float(row, ("bid", "best_bid", "bid_price"))
    ask_price = _first_float(row, ("ask", "best_ask", "ask_price"))
    spread_percent = None

    if bid_price and ask_price and last_price:
        spread_percent = ((ask_price - bid_price) / last_price) * 100

    return {
        "symbol": symbol,
        "last_price": last_price,
        "high_24h": _first_float(row, ("high", "high_24h")),
        "low_24h": _first_float(row, ("low", "low_24h")),
        "volume_24h": _first_float(row, ("volume", "volume_24h")),
        "quote_volume_24h": _first_float(row, ("quote_volume", "quote_volume_24h")),
        "change_24h_percent": _first_float(
            row,
            (
                "change_24_hour",
                "change_24h",
                "change_24h_percent",
                "percent_change",
            ),
        ),
        "bid_price": bid_price,
        "ask_price": ask_price,
        "spread_percent": spread_percent,
        "raw": row,
    }


def _first_float(row: dict, keys: tuple[str, ...]):
    for key in keys:
        value = safe_float(row.get(key))
        if value is not None:
            return value
    return None


class TickerSnapshotCollector:
    def __init__(self, client: CoinDCXPublicClient = None):
        self.client = client or CoinDCXPublicClient()

    def run(self) -> dict:
        summary = {
            "active_symbols": 0,
            "ticker_rows": 0,
            "matched_symbols": 0,
            "scanner_metric_rows_inserted": 0,
            "market_snapshot_inserted": False,
            "skipped": 0,
            "errors": [],
        }

        try:
            active_symbols = self._load_active_symbols()
            summary["active_symbols"] = len(active_symbols)

            ticker_response = self.client.ticker()
            ticker_rows = self._coerce_ticker_rows(ticker_response)
            summary["ticker_rows"] = len(ticker_rows)

            normalized_by_symbol = {}
            for row in ticker_rows:
                try:
                    normalized = normalize_ticker_row(row)
                    if not normalized["symbol"]:
                        summary["skipped"] += 1
                        continue
                    normalized_by_symbol[normalized["symbol"]] = normalized
                except Exception as exc:  # defensive row-level guard
                    summary["skipped"] += 1
                    summary["errors"].append(f"Skipped ticker row: {exc}")

            now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
            metric_params = []
            matched_symbols = set()

            for symbol, spot_symbol in active_symbols.items():
                ticker = normalized_by_symbol.get(symbol)
                if ticker is None:
                    continue

                matched_symbols.add(symbol)
                metric_params.append(
                    (
                        spot_symbol["id"],
                        spot_symbol["coindcx_symbol"],
                        now,
                        ticker["change_24h_percent"],
                        ticker["quote_volume_24h"],
                        ticker["spread_percent"],
                        ticker["bid_price"],
                        ticker["ask_price"],
                        json.dumps({"api_pair": spot_symbol.get("api_pair"), "ticker": ticker["raw"]}, separators=(",", ":"), default=str),
                        0,
                        0,
                        now,
                        now,
                    )
                )

            summary["matched_symbols"] = len(matched_symbols)
            if metric_params:
                summary["scanner_metric_rows_inserted"] = self._insert_scanner_metrics(metric_params)

            summary["market_snapshot_inserted"] = self._insert_market_snapshot(normalized_by_symbol, now)

            status = "warning" if summary["active_symbols"] == 0 else "ok"
            message = (
                f"active_symbols={summary['active_symbols']}, "
                f"ticker_rows={summary['ticker_rows']}, "
                f"matched_symbols={summary['matched_symbols']}, "
                f"scanner_metric_rows_inserted={summary['scanner_metric_rows_inserted']}"
            )
            if summary["active_symbols"] == 0:
                message = f"No active spot symbols found. {message}"

            write_health_log(SERVICE_NAME, status, message, summary)
            return summary
        except Exception as exc:
            summary["errors"].append(str(exc))
            try:
                write_health_log(SERVICE_NAME, "error", str(exc), summary)
            except Exception:
                pass
            raise

    def _load_active_symbols(self) -> dict:
        rows = fetch_all(
            """
            SELECT id, coindcx_symbol, api_pair, base_asset, quote_asset
            FROM spot_symbols
            WHERE is_active = 1
            """
        )
        return {normalize_symbol(row["coindcx_symbol"]): row for row in rows if normalize_symbol(row.get("coindcx_symbol"))}

    def _coerce_ticker_rows(self, ticker_response) -> list:
        if isinstance(ticker_response, list):
            return [row for row in ticker_response if isinstance(row, dict)]
        if isinstance(ticker_response, dict):
            for key in ("data", "tickers", "ticker", "result"):
                value = ticker_response.get(key)
                if isinstance(value, list):
                    return [row for row in value if isinstance(row, dict)]
        return []

    def _insert_scanner_metrics(self, metric_params: list[tuple]) -> int:
        return execute_many(
            """
            INSERT INTO scanner_metrics
                (spot_symbol_id, coindcx_symbol, metric_time, change_24h_percent,
                 quote_volume_24h, spread_percent, bid_price, ask_price, raw_payload,
                 passes_watchlist, passes_strong, created_at, updated_at)
            VALUES
                (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            """,
            metric_params,
        )

    def _insert_market_snapshot(self, normalized_by_symbol: dict, now: str) -> bool:
        btc = normalized_by_symbol.get("BTCUSDT") or normalized_by_symbol.get("BTCINR")
        eth = normalized_by_symbol.get("ETHUSDT") or normalized_by_symbol.get("ETHINR")
        btc_change = btc.get("change_24h_percent") if btc else None

        market_condition = "unknown"
        if btc_change is not None:
            if btc_change >= 2:
                market_condition = "bullish"
            elif btc_change <= -2:
                market_condition = "bearish"
            else:
                market_condition = "neutral"

        raw_payload = {
            "btc": self._compact_market_context(btc),
            "eth": self._compact_market_context(eth),
        }

        execute(
            """
            INSERT INTO market_snapshots
                (snapshot_time, btc_price, eth_price, btc_change_24h_percent,
                 eth_change_24h_percent, market_condition, notes, raw_payload,
                 created_at, updated_at)
            VALUES
                (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            """,
            (
                now,
                btc.get("last_price") if btc else None,
                eth.get("last_price") if eth else None,
                btc_change,
                eth.get("change_24h_percent") if eth else None,
                market_condition,
                "Ticker snapshot collector one-shot run",
                json.dumps(raw_payload, separators=(",", ":"), default=str),
                now,
                now,
            ),
        )
        return True

    def _compact_market_context(self, ticker: dict | None) -> dict | None:
        if ticker is None:
            return None
        return {
            "symbol": ticker.get("symbol"),
            "last_price": ticker.get("last_price"),
            "change_24h_percent": ticker.get("change_24h_percent"),
            "quote_volume_24h": ticker.get("quote_volume_24h"),
        }


def main() -> int:
    try:
        summary = TickerSnapshotCollector().run()
    except Exception as exc:
        print(f"Ticker snapshot collector failed: {exc}", file=sys.stderr)
        return 1

    print("Ticker snapshot collector summary:")
    print(json.dumps(summary, indent=2, default=str))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
