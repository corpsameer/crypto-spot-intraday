import json
from datetime import datetime
from typing import Any

from cryptospot.coindcx_client import CoinDCXPublicClient
from cryptospot.db import execute, fetch_all
from cryptospot.health import write_health_log
from cryptospot.logger import get_logger
from cryptospot.portfolio_pnl_manager import PortfolioPnlManager
from cryptospot.scan_runner import extract_ticker_symbol, normalize_symbol, safe_float

SERVICE_NAME = "active_trade_monitor"
PRICE_KEYS = ("last_price", "last", "price", "close")
OPEN_STATUSES = ("active", "tp1_hit", "tp2_hit", "trailing_active")

logger = get_logger(__name__)


class ActiveTradeMonitor:
    def __init__(self, client: CoinDCXPublicClient = None):
        self.client = client or CoinDCXPublicClient()
        self.portfolio_pnl_manager = PortfolioPnlManager()

    def run_once(self, limit: int = None) -> dict:
        summary = {
            "trades_loaded": 0,
            "symbols_checked": 0,
            "ticker_rows_fetched": 0,
            "prices_matched": 0,
            "trades_updated": 0,
            "portfolio_trades_updated": 0,
            "portfolio_accounts_refreshed": 0,
            "portfolio_unrealized_pnl_total": 0.0,
            "portfolio_total_equity": 0.0,
            "portfolio_skipped_legacy_trades": 0,
            "skipped": 0,
            "errors": [],
        }

        try:
            trades = self._load_active_trades(limit)
            summary["trades_loaded"] = len(trades)
            symbols = {normalize_symbol(t.get("coindcx_symbol")) for t in trades if normalize_symbol(t.get("coindcx_symbol"))}
            summary["symbols_checked"] = len(symbols)

            if not trades:
                self._write_health("ok", "Updated 0 active simulated trades", summary)
                return summary

            tickers = self.client.ticker()
            if not isinstance(tickers, list):
                raise RuntimeError("CoinDCX ticker response was not a list")
            summary["ticker_rows_fetched"] = len(tickers)
            price_map = self._build_price_map(tickers)

            now = datetime.now()
            affected_portfolio_ids = set()
            for trade in trades:
                try:
                    matched = self._process_trade(trade, price_map, now, summary, affected_portfolio_ids)
                    if matched:
                        summary["prices_matched"] += 1
                except Exception as exc:
                    logger.exception("Failed to update simulated_trade id=%s", trade.get("id"))
                    summary["errors"].append({"trade_id": trade.get("id"), "error": str(exc)})
                    summary["skipped"] += 1

            self._refresh_affected_portfolios(affected_portfolio_ids, summary)

            status = "warning" if summary["errors"] else "ok"
            message = f"Updated {summary['trades_updated']} active simulated trades"
            if summary["errors"]:
                message = f"{message}, errors {len(summary['errors'])}"
            self._write_health(status, message, summary)
            return summary
        except Exception as exc:
            logger.exception("Active trade monitor failed")
            summary["errors"].append({"fatal": str(exc)})
            self._write_health("error", str(exc), summary)
            raise

    def _load_active_trades(self, limit: int = None) -> list[dict]:
        query = """
            SELECT
                id, scan_run_id, scan_result_id, candidate_watchlist_id, trade_plan_id,
                spot_symbol_id, scanner_metric_id, coindcx_symbol, api_pair, base_asset,
                quote_asset, side, status, source, planned_entry_price, trigger_price,
                entry_price, entry_triggered_at, tp1_price, tp2_price, sl_price,
                trailing_start_price, current_trailing_sl_price, tp1_percent, tp2_percent,
                sl_percent, trailing_active, latest_price, highest_price, lowest_price,
                max_gain_percent, max_drawdown_percent, current_pnl_percent,
                final_pnl_percent, tp1_hit_at, tp2_hit_at, sl_hit_at, trailing_started_at,
                trailing_stopped_at, closed_at, expires_at, close_price, close_reason,
                score, score_label, entry_strategy, raw_payload,
                portfolio_account_id, allocated_capital, quantity, entry_value,
                current_value, unrealized_pnl_amount, realized_pnl_amount,
                fees_amount, net_pnl_amount, capital_released_at
            FROM simulated_trades
            WHERE status IN ('active', 'tp1_hit', 'tp2_hit', 'trailing_active')
            AND closed_at IS NULL
            ORDER BY entry_triggered_at ASC, updated_at ASC
        """
        params = None
        if limit is not None and int(limit) > 0:
            query += " LIMIT %s"
            params = (int(limit),)
        return fetch_all(query, params)

    def _build_price_map(self, tickers: list[dict]) -> dict[str, float]:
        prices = {}
        for row in tickers:
            if not isinstance(row, dict):
                continue
            symbol = extract_ticker_symbol(row)
            if not symbol:
                continue
            price = self._first_price(row)
            if price is not None and price > 0:
                prices[normalize_symbol(symbol)] = price
        return prices

    def _process_trade(self, trade: dict, price_map: dict[str, float], now: datetime, summary: dict, affected_portfolio_ids: set) -> bool:
        trade_id = trade["id"]
        symbol = normalize_symbol(trade.get("coindcx_symbol"))
        latest_price = price_map.get(symbol)
        if latest_price is None:
            summary["skipped"] += 1
            return False

        entry_price = safe_float(trade.get("entry_price"))
        if entry_price is None or entry_price <= 0:
            summary["errors"].append({"trade_id": trade_id, "error": "missing_or_invalid_entry_price"})
            summary["skipped"] += 1
            return True

        highest = self._highest(trade.get("highest_price"), entry_price, latest_price)
        lowest = self._lowest(trade.get("lowest_price"), entry_price, latest_price)
        current_pnl = ((latest_price - entry_price) / entry_price) * 100
        max_gain = ((highest - entry_price) / entry_price) * 100
        max_drawdown = ((lowest - entry_price) / entry_price) * 100
        raw_payload = self._merged_payload(trade, now, latest_price, highest, lowest, current_pnl, max_gain, max_drawdown)

        execute(
            """
            UPDATE simulated_trades
            SET latest_price = %s,
                highest_price = %s,
                lowest_price = %s,
                current_pnl_percent = %s,
                max_gain_percent = %s,
                max_drawdown_percent = %s,
                raw_payload = %s,
                updated_at = %s
            WHERE id = %s
            """,
            (latest_price, highest, lowest, current_pnl, max_gain, max_drawdown, json.dumps(raw_payload, default=str), now, trade_id),
        )
        summary["trades_updated"] += 1
        self._update_portfolio_pnl(trade, latest_price, summary, affected_portfolio_ids)
        return True

    def _update_portfolio_pnl(self, trade: dict, latest_price: float, summary: dict, affected_portfolio_ids: set) -> None:
        trade_id = trade.get("id")
        portfolio_account_id = trade.get("portfolio_account_id")
        if not portfolio_account_id or trade.get("allocated_capital") in (None, "") or trade.get("quantity") in (None, ""):
            summary["portfolio_skipped_legacy_trades"] += 1
            return
        if latest_price is None or latest_price <= 0:
            summary["errors"].append({"trade_id": trade_id, "error": "missing_or_invalid_latest_price_for_portfolio_pnl"})
            return
        pnl = self.portfolio_pnl_manager.calculate_trade_pnl(trade, latest_price)
        self.portfolio_pnl_manager.update_trade_pnl(trade_id, pnl)
        affected_portfolio_ids.add(portfolio_account_id)
        summary["portfolio_trades_updated"] += 1

    def _refresh_affected_portfolios(self, portfolio_ids: set, summary: dict) -> None:
        for portfolio_id in sorted(portfolio_ids):
            try:
                account_summary = self.portfolio_pnl_manager.refresh_portfolio_equity(portfolio_id)
                summary["portfolio_accounts_refreshed"] += 1
                summary["portfolio_unrealized_pnl_total"] += account_summary.get("unrealized_pnl", 0.0)
                summary["portfolio_total_equity"] += account_summary.get("total_equity", 0.0)
            except Exception as exc:
                logger.exception("Failed to refresh portfolio_account id=%s", portfolio_id)
                summary["errors"].append({"portfolio_account_id": portfolio_id, "error": str(exc)})


    def _merged_payload(self, trade: dict, now, latest, highest, lowest, pnl, gain, drawdown) -> dict:
        payload = self._decode_payload(trade.get("raw_payload"))
        monitor = {
            "last_checked_at": now.strftime("%Y-%m-%d %H:%M:%S"),
            "latest_price": latest,
            "highest_price": highest,
            "lowest_price": lowest,
            "current_pnl_percent": pnl,
            "max_gain_percent": gain,
            "max_drawdown_percent": drawdown,
            "tp1_price": safe_float(trade.get("tp1_price")),
            "tp2_price": safe_float(trade.get("tp2_price")),
            "sl_price": safe_float(trade.get("sl_price")),
            "trailing_start_price": safe_float(trade.get("trailing_start_price")),
            "expires_at": self._format_dt(trade.get("expires_at")),
        }
        expires_at = self._parse_dt(trade.get("expires_at"))
        monitor["expired_candidate"] = expires_at is not None and now > expires_at
        tp1 = safe_float(trade.get("tp1_price")); tp2 = safe_float(trade.get("tp2_price")); sl = safe_float(trade.get("sl_price"))
        monitor["tp1_condition_met"] = bool(tp1 is not None and tp1 > 0 and latest >= tp1)
        monitor["tp2_condition_met"] = bool(tp2 is not None and tp2 > 0 and latest >= tp2)
        monitor["sl_condition_met"] = bool(sl is not None and sl > 0 and latest <= sl)
        payload["active_trade_monitor"] = monitor
        return payload

    @staticmethod
    def _decode_payload(raw_payload: Any) -> dict:
        if isinstance(raw_payload, dict):
            return raw_payload
        if raw_payload:
            try:
                decoded = json.loads(raw_payload)
                return decoded if isinstance(decoded, dict) else {}
            except (TypeError, ValueError):
                return {}
        return {}

    @staticmethod
    def _first_price(row):
        for key in PRICE_KEYS:
            price = safe_float(row.get(key))
            if price is not None:
                return price
        return None

    @staticmethod
    def _highest(existing, entry, latest):
        existing = safe_float(existing)
        return max(entry, latest) if existing is None else max(existing, latest)

    @staticmethod
    def _lowest(existing, entry, latest):
        existing = safe_float(existing)
        return min(entry, latest) if existing is None else min(existing, latest)

    @staticmethod
    def _format_dt(value):
        return value.strftime("%Y-%m-%d %H:%M:%S") if hasattr(value, "strftime") else value

    @staticmethod
    def _parse_dt(value):
        if value is None or hasattr(value, "strftime"):
            return value
        if isinstance(value, str):
            for fmt in ("%Y-%m-%d %H:%M:%S", "%Y-%m-%dT%H:%M:%S"):
                try:
                    return datetime.strptime(value[:19], fmt)
                except ValueError:
                    continue
        return None

    @staticmethod
    def _write_health(status, message, meta):
        try:
            write_health_log(SERVICE_NAME, status, message, meta)
        except Exception:
            logger.exception("Failed to write active trade monitor health log")
