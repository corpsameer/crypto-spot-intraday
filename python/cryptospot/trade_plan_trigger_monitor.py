import json
from datetime import datetime
from typing import Any

from cryptospot.coindcx_client import CoinDCXPublicClient
from cryptospot.db import execute, fetch_all
from cryptospot.health import write_health_log
from cryptospot.logger import get_logger
from cryptospot.scan_runner import normalize_symbol, safe_float, extract_ticker_symbol

SERVICE_NAME = "trade_plan_trigger_monitor"

logger = get_logger(__name__)


PRICE_KEYS = ("last_price", "last", "price", "close")


class TradePlanTriggerMonitor:
    def __init__(self, client: CoinDCXPublicClient = None):
        self.client = client or CoinDCXPublicClient()

    def run_once(self, limit: int = None) -> dict:
        summary = {
            "plans_loaded": 0,
            "symbols_checked": 0,
            "ticker_rows_fetched": 0,
            "prices_matched": 0,
            "plans_updated": 0,
            "plans_triggered": 0,
            "plans_expired": 0,
            "skipped": 0,
            "errors": [],
        }

        try:
            plans = self._load_active_plans(limit)
            summary["plans_loaded"] = len(plans)
            symbols = {normalize_symbol(plan.get("coindcx_symbol")) for plan in plans if normalize_symbol(plan.get("coindcx_symbol"))}
            summary["symbols_checked"] = len(symbols)

            if not plans:
                self._write_health("ok", "Checked 0 trade plans, triggered 0, expired 0", summary)
                return summary

            tickers = self.client.ticker()
            if not isinstance(tickers, list):
                raise RuntimeError("CoinDCX ticker response was not a list")
            summary["ticker_rows_fetched"] = len(tickers)
            price_map = self._build_price_map(tickers)

            now = datetime.now()
            for plan in plans:
                try:
                    matched = self._process_plan(plan, price_map, now, summary)
                    if matched:
                        summary["prices_matched"] += 1
                except Exception as exc:  # continue per-row as requested
                    logger.exception("Failed to update trade_plan id=%s", plan.get("id"))
                    summary["errors"].append({"plan_id": plan.get("id"), "error": str(exc)})
                    summary["skipped"] += 1

            status = "warning" if summary["errors"] else "ok"
            message = f"Checked {summary['plans_loaded']} trade plans, triggered {summary['plans_triggered']}, expired {summary['plans_expired']}"
            if summary["errors"]:
                message = f"{message}, errors {len(summary['errors'])}"
            self._write_health(status, message, summary)
            return summary
        except Exception as exc:
            logger.exception("Trade plan trigger monitor failed")
            summary["errors"].append({"fatal": str(exc)})
            self._write_health("error", str(exc), summary)
            raise

    def _load_active_plans(self, limit: int = None) -> list[dict]:
        query = """
            SELECT
                id, scan_run_id, scan_result_id, candidate_watchlist_id, spot_symbol_id,
                coindcx_symbol, api_pair, base_asset, quote_asset, plan_type, entry_strategy,
                status, score, score_label, reference_price, trigger_price, confirmation_price,
                entry_price, entry_condition, tp1_price, tp2_price, sl_price,
                trailing_start_price, tp1_percent, tp2_percent, sl_percent, risk_reward_ratio,
                valid_from, expires_at, triggered_at, latest_price, highest_price_seen,
                lowest_price_seen, max_plan_gain_percent, max_plan_drawdown_percent, raw_payload
            FROM trade_plans
            WHERE status IN ('pending', 'watching')
              AND converted_at IS NULL
              AND simulated_trade_id IS NULL
              AND COALESCE(portfolio_status, '') <> 'rejected'
              AND status NOT IN ('expired', 'portfolio_rejected', 'converted_to_trade', 'cancelled')
            ORDER BY score DESC, updated_at ASC
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
            if price is not None:
                prices[normalize_symbol(symbol)] = price
        return prices

    def _process_plan(self, plan: dict, price_map: dict[str, float], now: datetime, summary: dict) -> bool:
        plan_id = plan["id"]
        latest_price = price_map.get(normalize_symbol(plan.get("coindcx_symbol")))
        if latest_price is None:
            summary["skipped"] += 1
            return False

        highest = self._highest(plan.get("highest_price_seen"), latest_price)
        lowest = self._lowest(plan.get("lowest_price_seen"), latest_price)
        gain, drawdown = self._gain_drawdown(plan, highest, lowest)
        raw_payload = self._merged_payload(plan.get("raw_payload"), now, latest_price, highest, lowest, gain, drawdown, False, None)

        trigger_met, warning = self._trigger_condition_met(plan, latest_price)
        if warning:
            summary["errors"].append({"plan_id": plan_id, "warning": warning})

        status = "triggered" if trigger_met else "watching"
        triggered_at = now if trigger_met else None
        if trigger_met:
            raw_payload = self._merged_payload(plan.get("raw_payload"), now, latest_price, highest, lowest, gain, drawdown, True, triggered_at, plan)

        self._update_plan(plan_id, status, latest_price, highest, lowest, gain, drawdown, triggered_at, raw_payload, now)
        summary["plans_updated"] += 1
        if trigger_met:
            summary["plans_triggered"] += 1
        return True

    def _update_plan(self, plan_id, status, latest_price, highest, lowest, gain, drawdown, triggered_at, raw_payload, now):
        execute(
            """
            UPDATE trade_plans
            SET status = %s, latest_price = %s, highest_price_seen = %s, lowest_price_seen = %s,
                max_plan_gain_percent = %s, max_plan_drawdown_percent = %s,
                triggered_at = COALESCE(%s, triggered_at), raw_payload = %s, updated_at = %s
            WHERE id = %s
            """,
            (status, latest_price, highest, lowest, gain, drawdown, triggered_at, json.dumps(raw_payload, default=str), now, plan_id),
        )

    def _trigger_condition_met(self, plan: dict, latest_price: float) -> tuple[bool, str | None]:
        trigger_price = safe_float(plan.get("trigger_price"))
        if trigger_price is None or trigger_price <= 0:
            return False, "missing_trigger_price"
        strategy = (plan.get("entry_strategy") or "").strip().lower()
        if strategy == "breakout":
            return latest_price >= trigger_price, None
        if strategy == "pullback":
            return latest_price <= trigger_price, None
        return False, f"unknown_entry_strategy:{strategy or 'blank'}"

    def _merged_payload(self, raw_payload: Any, now, latest, highest, lowest, gain, drawdown, trigger_met, triggered_at, plan=None):
        payload = {}
        if isinstance(raw_payload, dict):
            payload = raw_payload
        elif raw_payload:
            try:
                decoded = json.loads(raw_payload)
                payload = decoded if isinstance(decoded, dict) else {}
            except (TypeError, ValueError):
                payload = {}
        monitor = {
            "last_checked_at": now.strftime("%Y-%m-%d %H:%M:%S"),
            "latest_price": latest,
            "highest_price_seen": highest,
            "lowest_price_seen": lowest,
            "max_plan_gain_percent": gain,
            "max_plan_drawdown_percent": drawdown,
            "trigger_condition_met": trigger_met,
            "triggered_at": triggered_at.strftime("%Y-%m-%d %H:%M:%S") if triggered_at else None,
        }
        if trigger_met and plan:
            monitor.update({
                "trigger_price": safe_float(plan.get("trigger_price")),
                "entry_strategy": plan.get("entry_strategy"),
                "condition": plan.get("entry_condition"),
            })
        payload["trigger_monitor"] = monitor
        return payload

    def _gain_drawdown(self, plan, highest, lowest):
        reference = safe_float(plan.get("entry_price")) or safe_float(plan.get("trigger_price")) or safe_float(plan.get("reference_price"))
        if reference is None or reference <= 0:
            return None, None
        return ((highest - reference) / reference) * 100, ((lowest - reference) / reference) * 100

    def _is_expired(self, plan, now):
        expires_at = plan.get("expires_at")
        return expires_at is not None and now > expires_at

    @staticmethod
    def _first_price(row):
        for key in PRICE_KEYS:
            price = safe_float(row.get(key))
            if price is not None:
                return price
        return None

    @staticmethod
    def _highest(existing, latest):
        existing = safe_float(existing)
        return latest if existing is None else max(existing, latest)

    @staticmethod
    def _lowest(existing, latest):
        existing = safe_float(existing)
        return latest if existing is None else min(existing, latest)

    @staticmethod
    def _write_health(status, message, meta):
        try:
            write_health_log(SERVICE_NAME, status, message, meta)
        except Exception:
            logger.exception("Failed to write trade plan trigger monitor health log")
