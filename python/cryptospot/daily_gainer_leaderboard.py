import json
from datetime import date, datetime
from typing import Any

from cryptospot.coindcx_client import CoinDCXPublicClient
from cryptospot.db import execute, execute_many, fetch_all, fetch_one
from cryptospot.health import write_health_log

SERVICE_NAME = "daily_gainer_leaderboard"
SOURCE = "coindcx_ticker"


def normalize_symbol(value: Any) -> str | None:
    if value is None:
        return None
    normalized = str(value).strip().upper().replace("/", "").replace("_", "").replace("-", "")
    return normalized or None


def safe_float(value: Any, default=None):
    if value in (None, ""):
        return default
    try:
        return float(value)
    except (TypeError, ValueError):
        return default


def json_default(value):
    if isinstance(value, (datetime, date)):
        return value.isoformat()
    return str(value)


class DailyGainerLeaderboardBuilder:
    def __init__(self, client: CoinDCXPublicClient | None = None):
        self.client = client or CoinDCXPublicClient()

    def run(self, leaderboard_date: str = None, quote_filter: str = "USDT", limit: int = 100) -> dict:
        resolved_date = leaderboard_date or date.today().isoformat()
        quote_filter = (quote_filter or "USDT").strip().upper() or "USDT"
        limit = max(int(limit or 100), 0)
        summary = {
            "leaderboard_date": resolved_date,
            "quote_filter": quote_filter,
            "ticker_rows_fetched": 0,
            "active_symbols": 0,
            "matched_symbols": 0,
            "rows_upserted": 0,
            "top_symbol": None,
            "top_change_24h_percent": None,
            "limit": limit,
            "errors": [],
        }

        try:
            tickers = self.client.ticker()
            if not isinstance(tickers, list):
                raise RuntimeError("CoinDCX ticker response was not a list")
            summary["ticker_rows_fetched"] = len(tickers)

            active_symbols = self._load_active_symbols()
            summary["active_symbols"] = len(active_symbols)
            symbol_map = {normalize_symbol(row.get("coindcx_symbol")): row for row in active_symbols if normalize_symbol(row.get("coindcx_symbol"))}

            candidates = []
            for ticker in tickers:
                if not isinstance(ticker, dict):
                    summary["errors"].append("Skipped malformed non-object ticker row")
                    continue
                normalized = self.normalize_ticker_row(ticker)
                ticker_symbol = normalize_symbol(normalized.get("symbol"))
                if not ticker_symbol or ticker_symbol not in symbol_map:
                    continue
                spot_symbol = symbol_map[ticker_symbol]
                if quote_filter != "ALL" and str(spot_symbol.get("quote_asset") or "").upper() != quote_filter:
                    continue
                if normalized.get("change_24h_percent") is None:
                    continue
                candidates.append({**normalized, "spot_symbol": spot_symbol})

            summary["matched_symbols"] = len(candidates)
            candidates.sort(key=lambda row: row.get("change_24h_percent") if row.get("change_24h_percent") is not None else -999999, reverse=True)
            selected = candidates[:limit]
            if selected:
                summary["top_symbol"] = selected[0]["spot_symbol"].get("coindcx_symbol")
                summary["top_change_24h_percent"] = selected[0].get("change_24h_percent")

            now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
            execute(
                "DELETE FROM daily_gainer_leaderboard WHERE leaderboard_date = %s AND quote_filter = %s AND source = %s",
                (resolved_date, quote_filter, SOURCE),
            )
            rows = []
            for index, item in enumerate(selected, start=1):
                spot = item["spot_symbol"]
                scan_match = self._best_scan_match(resolved_date, spot.get("coindcx_symbol"))
                simulated_trade_created = self._simulated_trade_exists(resolved_date, spot.get("coindcx_symbol"))
                raw_payload = {
                    "normalized": {k: v for k, v in item.items() if k not in ("raw", "spot_symbol")},
                    "raw_ticker": item.get("raw"),
                    "builder": {"source": SOURCE, "quote_filter": quote_filter, "limit": limit, "run_time": now},
                }
                rows.append((
                    resolved_date, now, SOURCE, quote_filter, index, spot.get("id"), spot.get("coindcx_symbol"), spot.get("api_pair"), spot.get("base_asset"), spot.get("quote_asset"),
                    item.get("last_price"), item.get("open_price_24h"), item.get("high_price_24h"), item.get("low_price_24h"), item.get("change_24h_percent"), abs(item.get("change_24h_percent")), item.get("volume_24h"), item.get("quote_volume_24h"), item.get("bid_price"), item.get("ask_price"), item.get("spread_percent"),
                    True, False, bool(scan_match), bool(scan_match and scan_match.get("selected_for_watchlist")), bool(scan_match and scan_match.get("trade_plan_created")), simulated_trade_created,
                    scan_match.get("scan_run_id") if scan_match else None, scan_match.get("id") if scan_match else None, scan_match.get("final_score") if scan_match else None, scan_match.get("score_label") if scan_match else None,
                    json.dumps(raw_payload, separators=(",", ":"), default=json_default), now, now,
                ))

            if rows:
                summary["rows_upserted"] = execute_many(self._insert_sql(), rows)
            status = "warning" if summary["errors"] or summary["rows_upserted"] == 0 else "ok"
            write_health_log(SERVICE_NAME, status, f"Built daily gainer leaderboard for {resolved_date}, rows {summary['rows_upserted']}", summary)
            return summary
        except Exception as exc:
            summary["errors"].append(str(exc))
            try:
                write_health_log(SERVICE_NAME, "error", str(exc), summary)
            except Exception:
                pass
            raise

    def _load_active_symbols(self) -> list[dict]:
        return fetch_all("""
            SELECT id, coindcx_symbol, api_pair, base_asset, quote_asset, is_active
            FROM spot_symbols
            WHERE is_active = 1
        """)

    def normalize_ticker_row(self, row: dict) -> dict:
        symbol = self.extract_ticker_symbol(row)
        bid_price = self._first_float(row, ["bid", "best_bid", "bid_price"])
        ask_price = self._first_float(row, ["ask", "best_ask", "ask_price"])
        spread = self._first_float(row, ["spread_percent"])
        if spread is None and bid_price and ask_price:
            mid = (bid_price + ask_price) / 2
            if mid:
                spread = ((ask_price - bid_price) / mid) * 100
        return {
            "symbol": symbol,
            "last_price": self._first_float(row, ["last_price", "last", "price", "close"]),
            "open_price_24h": self._first_float(row, ["open", "open_price", "open_price_24h"]),
            "high_price_24h": self._first_float(row, ["high", "high_price", "high_price_24h"]),
            "low_price_24h": self._first_float(row, ["low", "low_price", "low_price_24h"]),
            "volume_24h": self._first_float(row, ["volume", "volume_24h"]),
            "quote_volume_24h": self._first_float(row, ["quote_volume", "quote_volume_24h", "quoteVolume"]),
            "change_24h_percent": self._first_float(row, ["change_24_hour", "change_24h", "change_24h_percent", "percent_change"]),
            "bid_price": bid_price,
            "ask_price": ask_price,
            "spread_percent": spread,
            "raw": row,
        }

    def extract_ticker_symbol(self, row: dict) -> str | None:
        for key in ("market", "symbol", "pair", "coindcx_name"):
            if row.get(key):
                return row.get(key)
        return None

    def _first_float(self, row: dict, keys: list[str]):
        for key in keys:
            value = safe_float(row.get(key))
            if value is not None:
                return value
        return None

    def _best_scan_match(self, leaderboard_date: str, coindcx_symbol: str) -> dict | None:
        return fetch_one("""
            SELECT sr.id, sr.scan_run_id, sr.selected_for_watchlist, sr.trade_plan_created, sr.final_score, sr.score_label
            FROM scan_results sr
            INNER JOIN scan_runs run ON run.id = sr.scan_run_id
            WHERE DATE(run.started_at) = %s AND sr.coindcx_symbol = %s
            ORDER BY sr.final_score DESC, sr.id DESC
            LIMIT 1
        """, (leaderboard_date, coindcx_symbol))

    def _simulated_trade_exists(self, leaderboard_date: str, coindcx_symbol: str) -> bool:
        row = fetch_one("""
            SELECT id FROM simulated_trades
            WHERE DATE(created_at) = %s AND coindcx_symbol = %s
            LIMIT 1
        """, (leaderboard_date, coindcx_symbol))
        return bool(row)

    def _insert_sql(self) -> str:
        return """
            INSERT INTO daily_gainer_leaderboard
            (leaderboard_date, run_time, source, quote_filter, rank, spot_symbol_id, coindcx_symbol, api_pair, base_asset, quote_asset,
             last_price, open_price_24h, high_price_24h, low_price_24h, change_24h_percent, abs_change_24h_percent, volume_24h, quote_volume_24h, bid_price, ask_price, spread_percent,
             is_top_gainer, is_top_loser, matched_in_scan, selected_for_watchlist, trade_plan_created, simulated_trade_created,
             best_scan_run_id, best_scan_result_id, best_final_score, best_score_label, raw_payload, created_at, updated_at)
            VALUES
            (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
        """
