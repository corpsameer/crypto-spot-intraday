import json
from datetime import datetime
from decimal import Decimal
from typing import Any, Callable

from cryptospot.db import execute, fetch_all, fetch_one, get_connection
from cryptospot.settings import get_setting

DEFAULTS = {
    "portfolio.reserve_cash_percent": 10.0,
    "portfolio.min_trade_capital": 10000.0,
    "portfolio.max_trade_capital": 40000.0,
    "portfolio.strong_score_min": 70.0,
    "portfolio.watchlist_score_min": 50.0,
    "portfolio.strong_allocation_capital": 40000.0,
    "portfolio.watchlist_allocation_capital": 30000.0,
    "portfolio.weak_allocation_capital": 20000.0,
    "portfolio.fallback_allocation_capital": 15000.0,
    "portfolio.reserve_capital_on_plan_creation": True,
    "portfolio.paper_fees_enabled": False,
    "portfolio.paper_fee_percent": 0.0,
}


def _float(value: Any, default: float = 0.0) -> float:
    if value is None or value == "":
        return default
    if isinstance(value, Decimal):
        return float(value)
    try:
        return float(str(value).replace(",", "").strip())
    except (TypeError, ValueError):
        return default


def _bool(value: Any, default: bool = False) -> bool:
    if isinstance(value, bool):
        return value
    if value is None:
        return default
    normalized = str(value).strip().lower()
    if normalized in ("true", "1", "yes", "on"):
        return True
    if normalized in ("false", "0", "no", "off"):
        return False
    return bool(value)


def _json_default(value: Any):
    if isinstance(value, Decimal):
        return float(value)
    if isinstance(value, datetime):
        return value.strftime("%Y-%m-%d %H:%M:%S")
    return str(value)


class CapitalAllocator:
    def __init__(self, db=None, settings_reader: Callable[[str, Any], Any] = None):
        self.db = db
        self.settings_reader = settings_reader or get_setting

    def allocate_for_candidate(self, candidate: dict, portfolio_account: dict) -> dict:
        account = portfolio_account or {}
        account_id = account.get("id")
        settings = self._settings()
        bucket, requested_allocation = self._allocation_bucket(candidate, settings)
        current_cash = _float(account.get("current_cash"), 0.0)
        reserved_cash = _float(account.get("reserved_cash"), 0.0)
        total_equity = _float(account.get("total_equity"), current_cash)
        available_cash = self.calculate_available_cash(account)
        reserve_cash_amount = total_equity * settings["reserve_cash_percent"] / 100
        tradable_cash = max(current_cash - reserved_cash - reserve_cash_amount, 0.0)
        capped_request = min(requested_allocation, settings["max_trade_capital"])
        final_allocation = min(capped_request, tradable_cash)
        allocation_percent = (final_allocation / total_equity * 100) if total_equity > 0 else 0.0
        details = {
            "score": _float(candidate.get("final_score", candidate.get("score")), 0.0),
            "score_label": candidate.get("score_label"),
            "selected_reason": candidate.get("selected_reason") or candidate.get("selection_reason") or candidate.get("selection_type"),
            "current_cash": round(current_cash, 2),
            "reserved_cash": round(reserved_cash, 2),
            "available_cash": round(available_cash, 2),
            "reserve_cash_amount": round(reserve_cash_amount, 2),
            "tradable_cash": round(tradable_cash, 2),
            "requested_allocation": round(requested_allocation, 2),
            "max_trade_capital": round(settings["max_trade_capital"], 2),
            "min_trade_capital": round(settings["min_trade_capital"], 2),
            "final_allocation": round(final_allocation, 2),
            "reserve_capital_on_plan_creation": settings["reserve_capital_on_plan_creation"],
            "paper_fees_enabled": settings["paper_fees_enabled"],
            "paper_fee_percent": settings["paper_fee_percent"],
        }
        if final_allocation < settings["min_trade_capital"]:
            return {
                "approved": False,
                "allocated_capital": 0.0,
                "allocation_percent": 0.0,
                "allocation_bucket": "none",
                "reason": "portfolio_rejected_insufficient_cash_after_reserve",
                "portfolio_account_id": account_id,
                "details": details,
            }
        return {
            "approved": True,
            "allocated_capital": round(final_allocation, 2),
            "allocation_percent": round(allocation_percent, 4),
            "allocation_bucket": bucket,
            "reason": f"allocated_{bucket}",
            "portfolio_account_id": account_id,
            "details": details,
        }

    def reserve_for_trade_plan(self, trade_plan_id: int, allocation: dict) -> dict:
        plan = fetch_one("SELECT * FROM trade_plans WHERE id = %s LIMIT 1", (trade_plan_id,))
        if not plan:
            return {"reserved": False, "created_transaction": False, "reason": "trade_plan_missing"}

        existing_tx = fetch_one(
            "SELECT id FROM portfolio_transactions WHERE trade_plan_id = %s AND transaction_type = 'capital_reserved' LIMIT 1",
            (trade_plan_id,),
        )
        if existing_tx or (plan.get("allocated_capital") and plan.get("capital_reserved_at")):
            execute("UPDATE trade_plans SET portfolio_status = 'capital_reserved', portfolio_rejection_reason = NULL, updated_at = %s WHERE id = %s", (self._now(), trade_plan_id))
            return {"reserved": True, "created_transaction": False, "reason": "already_reserved", "transaction_id": existing_tx.get("id") if existing_tx else None}

        reserve_on_creation = _bool(self.settings_reader("portfolio.reserve_capital_on_plan_creation", True), True)
        account_id = allocation.get("portfolio_account_id") or plan.get("portfolio_account_id")
        account = fetch_one("SELECT * FROM portfolio_accounts WHERE id = %s LIMIT 1", (account_id,))
        if not account:
            return {"reserved": False, "created_transaction": False, "reason": "portfolio_account_missing"}

        allocated = _float(allocation.get("allocated_capital"), 0.0)
        status = "capital_reserved" if reserve_on_creation else "approved"
        reserved_at = self._now() if reserve_on_creation else None
        if not reserve_on_creation:
            self._update_trade_plan_allocation(trade_plan_id, plan, allocation, status, reserved_at)
            return {"reserved": False, "created_transaction": False, "reason": "reservation_disabled"}

        old_current_cash = _float(account.get("current_cash"), 0.0)
        old_reserved = _float(account.get("reserved_cash"), 0.0)
        new_reserved = old_reserved + allocated
        old_deployed = _float(account.get("deployed_capital"), 0.0)
        old_realized = _float(account.get("realized_pnl"), 0.0)
        symbol = plan.get("coindcx_symbol")
        now = self._now()
        raw_payload = {"allocation": allocation}
        transaction_sql = """
            INSERT INTO portfolio_transactions
            (portfolio_account_id, trade_plan_id, simulated_trade_id, transaction_type, direction, amount,
             balance_before, balance_after, reserved_before, reserved_after, deployed_before, deployed_after,
             realized_pnl_before, realized_pnl_after, description, reference_type, reference_id,
             transaction_time, raw_payload, created_at, updated_at)
            VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s)
        """
        transaction_params = (
            account_id,
            trade_plan_id,
            None,
            "capital_reserved",
            "neutral",
            allocated,
            old_current_cash,
            old_current_cash,
            old_reserved,
            new_reserved,
            old_deployed,
            old_deployed,
            old_realized,
            old_realized,
            f"Reserved capital for trade plan {symbol}",
            "trade_plan",
            trade_plan_id,
            now,
            json.dumps(raw_payload, separators=(",", ":"), default=_json_default),
            now,
            now,
        )
        self._assert_placeholder_count(transaction_sql, transaction_params)

        connection = get_connection()
        cursor = None
        try:
            cursor = connection.cursor()
            cursor.execute("UPDATE portfolio_accounts SET reserved_cash = %s, updated_at = %s WHERE id = %s", (new_reserved, now, account_id))
            self._update_trade_plan_allocation(trade_plan_id, plan, allocation, status, reserved_at, cursor=cursor)
            cursor.execute(transaction_sql, transaction_params)
            transaction_id = cursor.lastrowid
            connection.commit()
            return {"reserved": True, "created_transaction": True, "reason": "capital_reserved", "reserved_before": old_reserved, "reserved_after": new_reserved, "transaction_id": transaction_id}
        except Exception:
            connection.rollback()
            raise
        finally:
            if cursor:
                cursor.close()
            connection.close()

    def calculate_available_cash(self, portfolio_account: dict) -> float:
        return max(_float(portfolio_account.get("current_cash"), 0.0) - _float(portfolio_account.get("reserved_cash"), 0.0), 0.0)

    def _settings(self) -> dict:
        return {
            key.split(".", 1)[1]: (_bool(self.settings_reader(key, default), default) if isinstance(default, bool) else _float(self.settings_reader(key, default), default))
            for key, default in DEFAULTS.items()
        }

    def _allocation_bucket(self, candidate: dict, settings: dict):
        selected = str(candidate.get("selected_reason") or candidate.get("selection_reason") or candidate.get("selection_type") or "").lower()
        score_label = str(candidate.get("score_label") or "").lower()
        score = _float(candidate.get("final_score", candidate.get("score")), 0.0)
        if selected == "fallback" or "fallback" in selected:
            return "fallback", settings["fallback_allocation_capital"]
        if score_label == "strong" or score >= settings["strong_score_min"]:
            return "strong", settings["strong_allocation_capital"]
        if score_label == "watchlist" or score >= settings["watchlist_score_min"]:
            return "watchlist", settings["watchlist_allocation_capital"]
        return "weak", settings["weak_allocation_capital"]

    def _update_trade_plan_allocation(self, trade_plan_id: int, plan: dict, allocation: dict, status: str, reserved_at, cursor=None):
        raw_payload = self._loads(plan.get("raw_payload"))
        raw_payload["portfolio_allocation"] = allocation
        notes = json.dumps({"allocation_bucket": allocation.get("allocation_bucket"), "reason": allocation.get("reason"), "details": allocation.get("details", {})}, separators=(",", ":"), default=_json_default)
        sql = """
            UPDATE trade_plans
            SET portfolio_account_id = %s, allocated_capital = %s, allocation_percent = %s,
                capital_reserved_at = %s, portfolio_status = %s, portfolio_rejection_reason = NULL,
                portfolio_notes = %s, raw_payload = %s, updated_at = %s
            WHERE id = %s
        """
        params = (
            allocation.get("portfolio_account_id"), allocation.get("allocated_capital"), allocation.get("allocation_percent"),
            reserved_at, status, notes, json.dumps(raw_payload, separators=(",", ":"), default=_json_default), self._now(), trade_plan_id,
        )
        self._assert_placeholder_count(sql, params)
        if cursor:
            cursor.execute(sql, params)
        else:
            execute(sql, params)

    def _assert_placeholder_count(self, sql: str, params: tuple):
        placeholder_count = sql.count("%s")
        if placeholder_count != len(params):
            raise ValueError(f"SQL placeholder mismatch: {placeholder_count} placeholders, {len(params)} params")

    def _loads(self, value):
        if isinstance(value, dict):
            return value
        if not value:
            return {}
        try:
            return json.loads(value)
        except (TypeError, ValueError):
            return {}

    def _now(self):
        return datetime.now().strftime("%Y-%m-%d %H:%M:%S")
