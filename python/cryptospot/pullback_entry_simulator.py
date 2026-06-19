import json
from datetime import datetime
from decimal import Decimal
from typing import Any

from cryptospot.db import fetch_all, fetch_one, get_connection
from cryptospot.health import write_health_log
from cryptospot.logger import get_logger
from cryptospot.portfolio_entry_manager import PortfolioEntryManager
from cryptospot.scan_runner import safe_float

SERVICE_NAME = "pullback_entry_simulator"
SIMULATION_NOTE = "Simulated pullback trade created from triggered trade plan. No real order placed."
ENTRY_EVENT_MESSAGE = "Pullback entry triggered and simulated trade opened."

logger = get_logger(__name__)


class PullbackEntrySimulator:
    def __init__(self):
        self.portfolio_entry_manager = PortfolioEntryManager()

    def run_once(self, limit: int = None) -> dict:
        summary = {
            "plans_loaded": 0,
            "trades_created": 0,
            "events_created": 0,
            "plans_converted": 0,
            "skipped": 0,
            "portfolio_deployments": 0,
            "portfolio_deployed_amount": 0.0,
            "portfolio_skipped_legacy_plans": 0,
            "errors": [],
        }

        try:
            plans = self._load_triggered_pullback_plans(limit)
            summary["plans_loaded"] = len(plans)

            for plan in plans:
                try:
                    self._convert_plan(plan, summary)
                except Exception as exc:
                    logger.exception("Failed to convert pullback trade_plan id=%s", plan.get("id"))
                    summary["errors"].append({"plan_id": plan.get("id"), "error": str(exc)})
                    summary["skipped"] += 1

            status = "warning" if summary["errors"] else "ok"
            message = f"Converted {summary['trades_created']} triggered pullback plans into simulated trades"
            if summary["errors"]:
                message = f"{message}; errors {len(summary['errors'])}"
            self._write_health(status, message, summary)
            return summary
        except Exception as exc:
            logger.exception("Pullback entry simulator failed")
            summary["errors"].append({"fatal": str(exc)})
            self._write_health("error", str(exc), summary)
            raise

    def _load_triggered_pullback_plans(self, limit: int = None) -> list[dict]:
        query = """
            SELECT
                tp.id,
                tp.scan_run_id,
                tp.scan_result_id,
                tp.candidate_watchlist_id,
                tp.spot_symbol_id,
                tp.simulated_trade_id,
                sr.scanner_metric_id,
                tp.coindcx_symbol,
                tp.api_pair,
                tp.base_asset,
                tp.quote_asset,
                tp.plan_type,
                tp.entry_strategy,
                tp.status,
                tp.score,
                tp.score_label,
                tp.reference_price,
                tp.trigger_price,
                tp.confirmation_price,
                tp.entry_price,
                tp.entry_condition,
                tp.tp1_price,
                tp.tp2_price,
                tp.sl_price,
                tp.trailing_start_price,
                tp.tp1_percent,
                tp.tp2_percent,
                tp.sl_percent,
                tp.risk_reward_ratio,
                tp.valid_from,
                tp.expires_at,
                tp.triggered_at,
                tp.latest_price,
                tp.highest_price_seen,
                tp.lowest_price_seen,
                tp.max_plan_gain_percent,
                tp.max_plan_drawdown_percent,
                tp.plan_reason,
                tp.notes,
                tp.portfolio_account_id,
                tp.allocated_capital,
                tp.allocation_percent,
                tp.portfolio_status,
                tp.capital_reserved_at,
                tp.capital_released_at,
                tp.raw_payload
            FROM trade_plans tp
            LEFT JOIN scan_results sr ON sr.id = tp.scan_result_id
            WHERE tp.status = 'triggered'
              AND tp.entry_strategy = 'pullback'
              AND tp.simulated_trade_id IS NULL
              AND tp.converted_at IS NULL
              AND COALESCE(tp.portfolio_status, '') <> 'rejected'
              AND tp.status NOT IN ('expired', 'portfolio_rejected', 'converted_to_trade', 'cancelled')
              AND NOT EXISTS (SELECT 1 FROM simulated_trades st WHERE st.trade_plan_id = tp.id LIMIT 1)
            ORDER BY tp.triggered_at ASC, tp.updated_at ASC
        """
        params = None
        if limit is not None and int(limit) > 0:
            query += " LIMIT %s"
            params = (int(limit),)
        return fetch_all(query, params)

    def _convert_plan(self, plan: dict, summary: dict) -> None:
        plan_id = plan["id"]
        now = datetime.now()
        entry_price, entry_price_source = self._select_entry_price(plan)
        if entry_price is None or entry_price <= 0:
            summary["errors"].append({"plan_id": plan_id, "error": "missing_entry_price"})
            summary["skipped"] += 1
            return

        existing = fetch_one(
            "SELECT id FROM simulated_trades WHERE trade_plan_id = %s ORDER BY id DESC LIMIT 1",
            (plan_id,),
        )
        if existing:
            fixed = self._fix_existing_conversion(plan, existing["id"], entry_price, now)
            summary["plans_converted"] += 1 if fixed else 0
            summary["skipped"] += 1
            return

        portfolio_fields = self._prepare_portfolio_fields(plan, entry_price, summary)
        if portfolio_fields is False:
            return

        connection = get_connection()
        cursor = None
        try:
            cursor = connection.cursor()
            trade_payload = self._trade_payload(plan, entry_price, now, portfolio_fields)
            cursor.execute(
                """
                INSERT INTO simulated_trades
                    (scan_run_id, scan_result_id, candidate_watchlist_id, trade_plan_id, spot_symbol_id, scanner_metric_id,
                     coindcx_symbol, api_pair, base_asset, quote_asset, side, status, source,
                     planned_entry_price, trigger_price, entry_price, entry_triggered_at,
                     portfolio_account_id, allocated_capital, allocation_percent, quantity, entry_value, current_value,
                     unrealized_pnl_amount, realized_pnl_amount, fees_amount, net_pnl_amount, capital_released_at,
                     tp1_price, tp2_price, sl_price, trailing_start_price, current_trailing_sl_price,
                     tp1_percent, tp2_percent, sl_percent, trailing_active,
                     latest_price, highest_price, lowest_price, max_gain_percent, max_drawdown_percent,
                     current_pnl_percent, final_pnl_percent, expires_at, score, score_label, entry_strategy,
                     notes, raw_payload, created_at, updated_at)
                VALUES
                    (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, 'long', 'active', 'trade_plan',
                     %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, NULL, %s, %s, %s, %s,
                     %s, %s, %s, 0, 0, 0, NULL, %s, %s, %s, 'pullback', %s, %s, %s, %s)
                """,
                (
                    plan.get("scan_run_id"), plan.get("scan_result_id"), plan.get("candidate_watchlist_id"), plan_id,
                    plan.get("spot_symbol_id"), plan.get("scanner_metric_id"), plan.get("coindcx_symbol"),
                    plan.get("api_pair"), plan.get("base_asset"), plan.get("quote_asset"), plan.get("entry_price"),
                    plan.get("trigger_price"), entry_price, self._entry_triggered_at(plan, now),
                    self._portfolio_value(portfolio_fields, "portfolio_account_id"), self._portfolio_value(portfolio_fields, "allocated_capital"),
                    self._portfolio_value(portfolio_fields, "allocation_percent"), self._portfolio_value(portfolio_fields, "quantity"),
                    self._portfolio_value(portfolio_fields, "entry_value"), self._portfolio_value(portfolio_fields, "current_value"),
                    self._portfolio_value(portfolio_fields, "unrealized_pnl_amount"), self._portfolio_value(portfolio_fields, "realized_pnl_amount"),
                    self._portfolio_value(portfolio_fields, "fees_amount"), self._portfolio_value(portfolio_fields, "net_pnl_amount"),
                    self._portfolio_value(portfolio_fields, "capital_released_at"), plan.get("tp1_price"),
                    plan.get("tp2_price"), plan.get("sl_price"), plan.get("trailing_start_price"), plan.get("tp1_percent"),
                    plan.get("tp2_percent"), plan.get("sl_percent"), False, entry_price, entry_price, entry_price,
                    plan.get("expires_at"), plan.get("score"), plan.get("score_label"), SIMULATION_NOTE,
                    json.dumps(trade_payload, separators=(",", ":"), default=self._json_default), now, now,
                ),
            )
            trade_id = cursor.lastrowid
            deployment_result = self._deploy_portfolio_if_needed(plan, trade_id, entry_price, portfolio_fields, summary, connection)
            if deployment_result is False:
                connection.rollback()
                return
            event_payload = self._event_payload(plan_id, trade_id, entry_price_source, portfolio_fields)
            cursor.execute(
                """
                INSERT INTO trade_events
                    (simulated_trade_id, trade_plan_id, scan_run_id, scan_result_id, candidate_watchlist_id, spot_symbol_id,
                     coindcx_symbol, event_type, event_time, event_price, previous_price, trigger_price,
                     actual_price_move_percent, pnl_percent, max_gain_percent, max_drawdown_percent,
                     previous_status, new_status, message, raw_payload, created_at, updated_at)
                VALUES
                    (%s, %s, %s, %s, %s, %s, %s, 'ENTRY_TRIGGERED', %s, %s, NULL, %s, 0, 0, 0, 0,
                     'triggered', 'active', %s, %s, %s, %s)
                """,
                (
                    trade_id, plan_id, plan.get("scan_run_id"), plan.get("scan_result_id"), plan.get("candidate_watchlist_id"),
                    plan.get("spot_symbol_id"), plan.get("coindcx_symbol"), self._entry_triggered_at(plan, now), entry_price,
                    plan.get("trigger_price"), ENTRY_EVENT_MESSAGE,
                    json.dumps(event_payload, separators=(",", ":"), default=self._json_default), now, now,
                ),
            )
            plan_payload = self._merged_plan_payload(plan.get("raw_payload"), now, trade_id)
            cursor.execute(
                """
                UPDATE trade_plans
                SET simulated_trade_id = %s, status = 'converted_to_trade', converted_at = COALESCE(converted_at, %s),
                    latest_price = %s, portfolio_status = CASE WHEN allocated_capital IS NOT NULL AND allocated_capital > 0 THEN 'deployed' ELSE portfolio_status END,
                    raw_payload = %s, updated_at = %s
                WHERE id = %s
                """,
                (trade_id, now, entry_price, json.dumps(plan_payload, separators=(",", ":"), default=self._json_default), now, plan_id),
            )
            connection.commit()
            summary["trades_created"] += 1
            summary["events_created"] += 1
            summary["plans_converted"] += 1
        except Exception:
            connection.rollback()
            raise
        finally:
            if cursor:
                cursor.close()
            connection.close()

    def _fix_existing_conversion(self, plan: dict, trade_id: int, entry_price: float, now: datetime) -> bool:
        if plan.get("simulated_trade_id") == trade_id and plan.get("status") == "converted_to_trade":
            return False
        payload = self._merged_plan_payload(plan.get("raw_payload"), now, trade_id)
        connection = get_connection()
        cursor = None
        try:
            cursor = connection.cursor()
            cursor.execute(
                """
                UPDATE trade_plans
                SET simulated_trade_id = %s, status = 'converted_to_trade', converted_at = COALESCE(converted_at, %s),
                    latest_price = %s, raw_payload = %s, updated_at = %s
                WHERE id = %s
                """,
                (trade_id, now, entry_price, json.dumps(payload, separators=(",", ":"), default=self._json_default), now, plan["id"]),
            )
            connection.commit()
            return cursor.rowcount > 0
        finally:
            if cursor:
                cursor.close()
            connection.close()

    def _select_entry_price(self, plan: dict) -> tuple[float | None, str | None]:
        for key in ("latest_price", "entry_price", "trigger_price"):
            value = safe_float(plan.get(key))
            if value is not None and value > 0:
                return value, key
        return None, None

    def _entry_triggered_at(self, plan: dict, now: datetime):
        return plan.get("triggered_at") or now

    def _trade_payload(self, plan: dict, entry_price: float, now: datetime, portfolio_fields: dict | None = None) -> dict:
        return {
            "source": SERVICE_NAME,
            "trade_plan_id": plan.get("id"),
            "scan_run_id": plan.get("scan_run_id"),
            "scan_result_id": plan.get("scan_result_id"),
            "candidate_watchlist_id": plan.get("candidate_watchlist_id"),
            "entry": {
                "entry_strategy": "pullback",
                "planned_entry_price": plan.get("entry_price"),
                "trigger_price": plan.get("trigger_price"),
                "observed_latest_price": plan.get("latest_price"),
                "simulated_entry_price": entry_price,
                "entry_triggered_at": self._entry_triggered_at(plan, now),
                "trigger_condition": "latest_price <= trigger_price",
            },
            "targets": {
                "tp1_price": plan.get("tp1_price"),
                "tp2_price": plan.get("tp2_price"),
                "sl_price": plan.get("sl_price"),
                "trailing_start_price": plan.get("trailing_start_price"),
            },
            "score": {"score": plan.get("score"), "score_label": plan.get("score_label")},
            "plan_snapshot": self._json_safe_plan(plan),
            "portfolio": self._portfolio_payload(portfolio_fields),
        }

    def _event_payload(self, plan_id: int, trade_id: int, entry_price_source: str | None, portfolio_fields: dict | None = None) -> dict:
        return {
            "source": SERVICE_NAME,
            "trade_plan_id": plan_id,
            "simulated_trade_id": trade_id,
            "entry_strategy": "pullback",
            "entry_price_source": "latest_price_or_entry_price_or_trigger_price",
            "selected_entry_price_field": entry_price_source,
            "trigger_condition": "latest_price <= trigger_price",
            "simulation_only": True,
            "portfolio": self._portfolio_payload(portfolio_fields),
        }

    def _merged_plan_payload(self, raw_payload: Any, now: datetime, trade_id: int) -> dict:
        payload = self._loads(raw_payload)
        payload["pullback_entry_simulator"] = {
            "converted_at": now.strftime("%Y-%m-%d %H:%M:%S"),
            "simulated_trade_id": trade_id,
            "entry_event_created": True,
        }
        return payload


    def _prepare_portfolio_fields(self, plan: dict, entry_price: float, summary: dict):
        allocated = safe_float(plan.get("allocated_capital")) or 0.0
        portfolio_status = plan.get("portfolio_status")
        if allocated <= 0:
            summary["portfolio_skipped_legacy_plans"] += 1
            return None
        if portfolio_status != "capital_reserved":
            summary["errors"].append({"plan_id": plan.get("id"), "error": "portfolio_status_not_capital_reserved", "portfolio_status": portfolio_status})
            summary["skipped"] += 1
            return False
        if not plan.get("portfolio_account_id"):
            summary["errors"].append({"plan_id": plan.get("id"), "error": "portfolio_account_id_missing"})
            summary["skipped"] += 1
            return False
        account = fetch_one("SELECT id, reserved_cash FROM portfolio_accounts WHERE id = %s LIMIT 1", (plan.get("portfolio_account_id"),))
        if not account:
            summary["errors"].append({"plan_id": plan.get("id"), "error": "portfolio_account_missing"})
            summary["skipped"] += 1
            return False
        reserved = safe_float(account.get("reserved_cash")) or 0.0
        if reserved + 0.000001 < allocated:
            summary["errors"].append({"plan_id": plan.get("id"), "error": "portfolio_reserved_cash_insufficient", "reserved_cash": reserved, "allocated_capital": allocated})
            summary["skipped"] += 1
            return False
        return self.portfolio_entry_manager.prepare_simulated_trade_portfolio_fields(plan, entry_price)

    def _deploy_portfolio_if_needed(self, plan: dict, trade_id: int, entry_price: float, portfolio_fields: dict | None, summary: dict, connection=None):
        if not portfolio_fields:
            return None
        payload = {
            "symbol": plan.get("coindcx_symbol"),
            "entry_price": entry_price,
            "allocated_capital": portfolio_fields.get("allocated_capital"),
            "quantity": portfolio_fields.get("quantity"),
            "entry_value": portfolio_fields.get("entry_value"),
        }
        if connection is not None:
            payload["_connection"] = connection
        result = self.portfolio_entry_manager.move_reserved_to_deployed(
            portfolio_fields.get("portfolio_account_id"), plan.get("id"), trade_id, portfolio_fields.get("allocated_capital"), payload
        )
        if not result.get("deployed"):
            summary["errors"].append({"plan_id": plan.get("id"), "error": result.get("reason"), "details": result})
            summary["skipped"] += 1
            return False
        summary["portfolio_deployments"] += 1
        summary["portfolio_deployed_amount"] = round(summary["portfolio_deployed_amount"] + float(portfolio_fields.get("allocated_capital") or 0), 2)
        return result

    def _portfolio_value(self, portfolio_fields: dict | None, key: str):
        return portfolio_fields.get(key) if portfolio_fields else None

    def _portfolio_payload(self, portfolio_fields: dict | None):
        if not portfolio_fields:
            return None
        return {
            "portfolio_account_id": portfolio_fields.get("portfolio_account_id"),
            "allocated_capital": portfolio_fields.get("allocated_capital"),
            "quantity": portfolio_fields.get("quantity"),
            "entry_value": portfolio_fields.get("entry_value"),
            "fees_amount": portfolio_fields.get("fees_amount"),
        }

    def _loads(self, raw_payload: Any) -> dict:
        if isinstance(raw_payload, dict):
            return dict(raw_payload)
        if raw_payload:
            try:
                decoded = json.loads(raw_payload)
                return decoded if isinstance(decoded, dict) else {"previous_raw_payload": decoded}
            except (TypeError, ValueError):
                return {"previous_raw_payload": str(raw_payload)}
        return {}

    def _json_safe_plan(self, plan: dict) -> dict:
        return {key: self._json_default(value) for key, value in plan.items() if key != "raw_payload"}

    def _write_health(self, status: str, message: str, summary: dict) -> None:
        try:
            write_health_log(SERVICE_NAME, status, message, summary)
        except Exception:
            logger.exception("Failed to write pullback entry simulator health log")

    @staticmethod
    def _json_default(value):
        if isinstance(value, (datetime,)):
            return value.strftime("%Y-%m-%d %H:%M:%S")
        if isinstance(value, Decimal):
            return float(value)
        return value
