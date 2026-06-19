import json
from datetime import datetime
from decimal import Decimal, InvalidOperation, ROUND_HALF_UP
from typing import Any, Callable

from cryptospot.db import fetch_all, fetch_one, get_connection
from cryptospot.health import write_health_log
from cryptospot.settings import get_setting

SERVICE_NAME = "portfolio_release_manager"
OPEN_STATUSES = ("active", "tp1_hit", "tp2_hit", "trailing_active")
CLOSED_STATUSES = ("closed", "closed_sl", "closed_tp", "closed_trailing", "trailing_stopped", "closed_manual", "stopped_out")
EXPIRY_REASONS = ("expiry", "expired", "time_expiry", "time_expired", "scan_cycle_expiry")
MONEY_QUANT = Decimal("0.01")
PERCENT_QUANT = Decimal("0.0001")


def _decimal(value: Any, default: Decimal = Decimal("0")) -> Decimal:
    if value is None or value == "":
        return default
    if isinstance(value, Decimal):
        return value
    try:
        return Decimal(str(value).replace(",", "").strip())
    except (InvalidOperation, TypeError, ValueError):
        return default


def _money(value: Decimal) -> Decimal:
    return value.quantize(MONEY_QUANT, rounding=ROUND_HALF_UP)


def _percent(value: Decimal) -> Decimal:
    return value.quantize(PERCENT_QUANT, rounding=ROUND_HALF_UP)


def _json_default(value: Any):
    if isinstance(value, Decimal):
        return float(value)
    if isinstance(value, datetime):
        return value.strftime("%Y-%m-%d %H:%M:%S")
    return str(value)


class PortfolioReleaseManager:
    def __init__(self, db=None, settings_reader: Callable[[str, Any], Any] = None):
        self.db = db
        self.settings_reader = settings_reader or get_setting
        self._column_cache: dict[tuple[str, str], bool] = {}

    def release_expired_trade_plan(self, trade_plan: dict) -> dict:
        trade_plan_id = trade_plan.get("id")
        allocated = _money(_decimal(trade_plan.get("allocated_capital")))
        portfolio_account_id = trade_plan.get("portfolio_account_id")
        if not trade_plan_id or not portfolio_account_id or allocated <= 0:
            return {"released": False, "type": "expired_trade_plan", "trade_plan_id": trade_plan_id, "reason": "missing_required_fields"}

        existing_tx = fetch_one("SELECT id FROM portfolio_transactions WHERE trade_plan_id = %s AND transaction_type = 'capital_released' LIMIT 1", (trade_plan_id,))
        if existing_tx:
            self._mark_plan_released_if_safe(trade_plan_id)
            return {"released": False, "type": "expired_trade_plan", "trade_plan_id": trade_plan_id, "portfolio_account_id": portfolio_account_id, "released_amount": float(allocated), "transaction_type": "capital_released", "reason": "capital_released_transaction_exists"}

        account = fetch_one("SELECT * FROM portfolio_accounts WHERE id = %s LIMIT 1", (portfolio_account_id,))
        if not account:
            return {"released": False, "type": "expired_trade_plan", "trade_plan_id": trade_plan_id, "reason": "portfolio_account_missing"}

        now = self._now()
        old_reserved = _decimal(account.get("reserved_cash")); new_reserved = max(old_reserved - allocated, Decimal("0"))
        current_cash = _decimal(account.get("current_cash")); deployed = _decimal(account.get("deployed_capital")); realized = _decimal(account.get("realized_pnl"))
        payload = {"source": SERVICE_NAME, "release_reason": "expired_untriggered_plan", "symbol": trade_plan.get("coindcx_symbol") or "", "allocated_capital": float(allocated)}
        raw_payload = self._merge_payload(trade_plan.get("raw_payload"), "portfolio_release", payload | {"released_at": now})
        notes = self._merge_payload(trade_plan.get("portfolio_notes"), "portfolio_release", payload | {"released_at": now})

        connection = get_connection(); cursor = None
        try:
            cursor = connection.cursor()
            cursor.execute("UPDATE portfolio_accounts SET reserved_cash = %s, updated_at = %s WHERE id = %s", (float(_money(new_reserved)), now, portfolio_account_id))
            cursor.execute(
                """
                UPDATE trade_plans
                SET portfolio_status = 'released', capital_released_at = %s, portfolio_notes = %s, raw_payload = %s, updated_at = %s
                WHERE id = %s AND capital_released_at IS NULL
                """,
                (now, json.dumps(notes, separators=(",", ":"), default=_json_default), json.dumps(raw_payload, separators=(",", ":"), default=_json_default), now, trade_plan_id),
            )
            cursor.execute(
                """
                INSERT INTO portfolio_transactions
                (portfolio_account_id, trade_plan_id, simulated_trade_id, transaction_type, direction, amount,
                 balance_before, balance_after, reserved_before, reserved_after, deployed_before, deployed_after,
                 realized_pnl_before, realized_pnl_after, description, reference_type, reference_id,
                 transaction_time, raw_payload, created_at, updated_at)
                VALUES (%s,%s,NULL,'capital_released','neutral',%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,'trade_plan',%s,%s,%s,%s,%s)
                """,
                (portfolio_account_id, trade_plan_id, float(allocated), float(current_cash), float(current_cash), float(_money(old_reserved)), float(_money(new_reserved)), float(_money(deployed)), float(_money(deployed)), float(_money(realized)), float(_money(realized)), f"Released reserved capital for expired trade plan {trade_plan.get('coindcx_symbol') or ''}", trade_plan_id, now, json.dumps(payload, separators=(",", ":"), default=_json_default), now, now),
            )
            connection.commit()
        except Exception:
            connection.rollback(); raise
        finally:
            if cursor: cursor.close()
            connection.close()
        return {"released": True, "type": "expired_trade_plan", "trade_plan_id": trade_plan_id, "portfolio_account_id": portfolio_account_id, "released_amount": float(allocated), "transaction_type": "capital_released", "reason": "expired_untriggered_plan"}

    def release_closed_trade(self, simulated_trade: dict) -> dict:
        trade_id = simulated_trade.get("id"); account_id = simulated_trade.get("portfolio_account_id")
        allocated = _money(_decimal(simulated_trade.get("allocated_capital")))
        if not trade_id or not account_id or allocated <= 0:
            return {"released": False, "type": "closed_trade", "simulated_trade_id": trade_id, "reason": "missing_required_fields"}
        if self._is_expiry_trade(simulated_trade) or not self._is_supported_closed_trade(simulated_trade):
            return {"released": False, "type": "closed_trade", "simulated_trade_id": trade_id, "reason": "unsupported_or_expiry_close"}
        existing_tx = fetch_one("SELECT id FROM portfolio_transactions WHERE simulated_trade_id = %s AND transaction_type = 'trade_exit' LIMIT 1", (trade_id,))
        if existing_tx:
            self._mark_trade_released_if_safe(trade_id)
            return {"released": False, "type": "closed_trade", "simulated_trade_id": trade_id, "transaction_type": "trade_exit", "reason": "trade_exit_transaction_exists"}
        account = fetch_one("SELECT * FROM portfolio_accounts WHERE id = %s LIMIT 1", (account_id,))
        if not account:
            return {"released": False, "type": "closed_trade", "simulated_trade_id": trade_id, "reason": "portfolio_account_missing"}

        quantity = _decimal(simulated_trade.get("quantity")); close_price = _decimal(simulated_trade.get("close_price") or simulated_trade.get("current_price") or simulated_trade.get("latest_price"))
        if quantity <= 0 or close_price <= 0:
            return {"released": False, "type": "closed_trade", "simulated_trade_id": trade_id, "reason": "missing_quantity_or_close_price"}
        close_value = _money(quantity * close_price); gross_pnl = _money(close_value - allocated); fees = _money(_decimal(simulated_trade.get("fees_amount"))); net_pnl = _money(gross_pnl - fees)
        now = self._now(); current_cash = _decimal(account.get("current_cash")); reserved = _decimal(account.get("reserved_cash")); old_deployed = _decimal(account.get("deployed_capital")); old_realized = _decimal(account.get("realized_pnl"))
        new_cash = _money(current_cash + net_pnl); new_deployed = max(old_deployed - allocated, Decimal("0")); new_realized = _money(old_realized + net_pnl)
        reason = simulated_trade.get("close_reason") or simulated_trade.get("exit_reason") or simulated_trade.get("status")
        payload = {"source": SERVICE_NAME, "release_reason": reason, "symbol": simulated_trade.get("coindcx_symbol") or "", "allocated_capital": float(allocated), "quantity": float(quantity), "close_price": float(close_price), "close_value": float(close_value), "gross_pnl": float(gross_pnl), "fees_amount": float(fees), "net_pnl": float(net_pnl)}
        direction = "credit" if net_pnl >= 0 else "debit"
        connection = get_connection(); cursor = None
        try:
            cursor = connection.cursor()
            cursor.execute("UPDATE portfolio_accounts SET current_cash = %s, deployed_capital = %s, realized_pnl = %s, updated_at = %s WHERE id = %s", (float(new_cash), float(_money(new_deployed)), float(new_realized), now, account_id))
            cursor.execute("""
                UPDATE simulated_trades
                SET close_value = %s, realized_pnl_amount = %s, net_pnl_amount = %s, unrealized_pnl_amount = 0,
                    current_value = %s, capital_released_at = %s, updated_at = %s
                WHERE id = %s AND capital_released_at IS NULL
            """, (float(close_value), float(gross_pnl), float(net_pnl), float(close_value), now, now, trade_id))
            cursor.execute("""
                INSERT INTO portfolio_transactions
                (portfolio_account_id, trade_plan_id, simulated_trade_id, transaction_type, direction, amount,
                 balance_before, balance_after, reserved_before, reserved_after, deployed_before, deployed_after,
                 realized_pnl_before, realized_pnl_after, description, reference_type, reference_id,
                 transaction_time, raw_payload, created_at, updated_at)
                VALUES (%s,%s,%s,'trade_exit',%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,'simulated_trade',%s,%s,%s,%s,%s)
            """, (account_id, simulated_trade.get("trade_plan_id"), trade_id, direction, float(net_pnl), float(_money(current_cash)), float(new_cash), float(_money(reserved)), float(_money(reserved)), float(_money(old_deployed)), float(_money(new_deployed)), float(_money(old_realized)), float(new_realized), f"Released deployed capital and realized P&L for simulated trade {simulated_trade.get('coindcx_symbol') or ''}", trade_id, now, json.dumps(payload, separators=(",", ":"), default=_json_default), now, now))
            connection.commit()
        except Exception:
            connection.rollback(); raise
        finally:
            if cursor: cursor.close()
            connection.close()
        refreshed = self.refresh_portfolio_account(account_id)
        return {"released": True, "type": "closed_trade", "simulated_trade_id": trade_id, "trade_plan_id": simulated_trade.get("trade_plan_id"), "portfolio_account_id": account_id, "allocated_capital": float(allocated), "close_value": float(close_value), "gross_pnl": float(gross_pnl), "fees_amount": float(fees), "net_pnl": float(net_pnl), "transaction_type": "trade_exit", "reason": reason, "portfolio_refresh": refreshed}

    def run_once(self, limit: int = 100) -> dict:
        return self.release_all_pending_items(limit=limit)

    def release_all_pending_items(self, limit: int = 100) -> dict:
        summary = {"expired_trade_plans_loaded": 0, "expired_trade_plans_released": 0, "closed_trades_loaded": 0, "closed_trades_released": 0, "capital_released_total": 0.0, "realized_pnl_total": 0.0, "portfolio_accounts_refreshed": 0, "skipped": 0, "errors": []}
        affected_accounts = set()
        plans = self._load_expired_trade_plans(limit); summary["expired_trade_plans_loaded"] = len(plans)
        for plan in plans:
            try:
                result = self.release_expired_trade_plan(plan)
                if result.get("released"):
                    summary["expired_trade_plans_released"] += 1; summary["capital_released_total"] += float(result.get("released_amount") or 0); affected_accounts.add(result.get("portfolio_account_id"))
                else: summary["skipped"] += 1
            except Exception as exc:
                summary["errors"].append({"trade_plan_id": plan.get("id"), "error": str(exc)})
        trades = self._load_closed_trades(limit); summary["closed_trades_loaded"] = len(trades)
        for trade in trades:
            try:
                result = self.release_closed_trade(trade)
                if result.get("released"):
                    summary["closed_trades_released"] += 1; summary["capital_released_total"] += float(result.get("allocated_capital") or 0); summary["realized_pnl_total"] += float(result.get("net_pnl") or 0); affected_accounts.add(result.get("portfolio_account_id"))
                else: summary["skipped"] += 1
            except Exception as exc:
                summary["errors"].append({"simulated_trade_id": trade.get("id"), "error": str(exc)})
        for account_id in {x for x in affected_accounts if x}:
            try:
                self.refresh_portfolio_account(int(account_id)); summary["portfolio_accounts_refreshed"] += 1
            except Exception as exc:
                summary["errors"].append({"portfolio_account_id": account_id, "error": str(exc)})
        summary["capital_released_total"] = round(summary["capital_released_total"], 2); summary["realized_pnl_total"] = round(summary["realized_pnl_total"], 2)
        status = "error" if summary["errors"] and (summary["expired_trade_plans_released"] + summary["closed_trades_released"] == 0) else ("warning" if summary["errors"] else "ok")
        write_health_log(SERVICE_NAME, status, f"Released capital for {summary['expired_trade_plans_released']} expired plans and {summary['closed_trades_released']} closed trades", summary)
        return summary

    def refresh_portfolio_account(self, portfolio_account_id: int) -> dict:
        account = fetch_one("SELECT * FROM portfolio_accounts WHERE id = %s LIMIT 1", (portfolio_account_id,))
        if not account:
            raise ValueError("portfolio_account_missing")
        placeholders = ",".join(["%s"] * len(OPEN_STATUSES))
        row = fetch_one(f"SELECT COALESCE(SUM(allocated_capital),0) AS deployed_capital, COALESCE(SUM(unrealized_pnl_amount),0) AS unrealized_pnl FROM simulated_trades WHERE portfolio_account_id = %s AND status IN ({placeholders}) AND closed_at IS NULL", (portfolio_account_id, *OPEN_STATUSES)) or {}
        current_cash = _decimal(account.get("current_cash")); starting = _decimal(account.get("starting_capital")); deployed = _money(_decimal(row.get("deployed_capital"))); unrealized = _money(_decimal(row.get("unrealized_pnl")))
        total_equity = _money(current_cash + unrealized); total_return = Decimal("0") if starting <= 0 else _percent(((total_equity - starting) / starting) * Decimal("100"))
        now = self._now(); connection = get_connection(); cursor = None
        try:
            cursor = connection.cursor()
            cursor.execute("UPDATE portfolio_accounts SET deployed_capital = %s, unrealized_pnl = %s, total_equity = %s, total_return_percent = %s, updated_at = %s WHERE id = %s", (float(deployed), float(unrealized), float(total_equity), float(total_return), now, portfolio_account_id))
            connection.commit()
        finally:
            if cursor: cursor.close()
            connection.close()
        return {"portfolio_account_id": portfolio_account_id, "deployed_capital": float(deployed), "unrealized_pnl": float(unrealized), "total_equity": float(total_equity), "total_return_percent": float(total_return)}

    def _load_expired_trade_plans(self, limit: int) -> list[dict]:
        converted_filter = "AND tp.converted_to_trade_at IS NULL" if self._has_column("trade_plans", "converted_to_trade_at") else ""
        return fetch_all(f"""
            SELECT tp.* FROM trade_plans tp
            WHERE tp.status = 'expired' AND tp.portfolio_status = 'capital_reserved' AND tp.allocated_capital > 0
              AND tp.capital_reserved_at IS NOT NULL AND tp.capital_released_at IS NULL {converted_filter}
              AND NOT EXISTS (SELECT 1 FROM simulated_trades st WHERE st.trade_plan_id = tp.id LIMIT 1)
            ORDER BY tp.updated_at ASC LIMIT %s
        """, (int(limit),))

    def _load_closed_trades(self, limit: int) -> list[dict]:
        placeholders = ",".join(["%s"] * len(CLOSED_STATUSES))
        reason_placeholders = ",".join(["%s"] * len(EXPIRY_REASONS))
        return fetch_all(f"""
            SELECT * FROM simulated_trades
            WHERE portfolio_account_id IS NOT NULL AND allocated_capital > 0 AND closed_at IS NOT NULL AND capital_released_at IS NULL
              AND status <> 'expired' AND COALESCE(close_reason, '') NOT IN ({reason_placeholders})
              AND (status IN ({placeholders}) OR (status = 'closed' AND COALESCE(close_reason, '') <> ''))
            ORDER BY closed_at ASC LIMIT %s
        """, (*EXPIRY_REASONS, *CLOSED_STATUSES, int(limit)))

    def _is_supported_closed_trade(self, trade: dict) -> bool:
        status = str(trade.get("status") or "").lower()
        reason = str(trade.get("close_reason") or trade.get("exit_reason") or "").lower()
        return bool(trade.get("closed_at")) and (status in CLOSED_STATUSES or (status == "closed" and reason)) and status not in OPEN_STATUSES

    def _is_expiry_trade(self, trade: dict) -> bool:
        return str(trade.get("status") or "").lower() == "expired" or str(trade.get("close_reason") or "").lower() in EXPIRY_REASONS

    def _mark_plan_released_if_safe(self, trade_plan_id: int) -> None:
        now = self._now(); connection = get_connection(); cursor = None
        try:
            cursor = connection.cursor(); cursor.execute("UPDATE trade_plans SET portfolio_status = 'released', capital_released_at = COALESCE(capital_released_at, %s), updated_at = %s WHERE id = %s AND portfolio_status = 'capital_reserved'", (now, now, trade_plan_id)); connection.commit()
        finally:
            if cursor: cursor.close()
            connection.close()

    def _mark_trade_released_if_safe(self, trade_id: int) -> None:
        now = self._now(); connection = get_connection(); cursor = None
        try:
            cursor = connection.cursor(); cursor.execute("UPDATE simulated_trades SET capital_released_at = COALESCE(capital_released_at, %s), updated_at = %s WHERE id = %s AND closed_at IS NOT NULL", (now, now, trade_id)); connection.commit()
        finally:
            if cursor: cursor.close()
            connection.close()

    def _merge_payload(self, raw: Any, key: str, value: dict) -> dict:
        if isinstance(raw, dict): payload = raw
        else:
            try: payload = json.loads(raw) if raw else {}
            except (TypeError, ValueError): payload = {"previous_value": str(raw)} if raw else {}
        payload[key] = value
        return payload

    def _has_column(self, table: str, column: str) -> bool:
        cache_key = (table, column)
        if cache_key not in self._column_cache:
            row = fetch_one("SELECT COUNT(*) AS cnt FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s", (table, column)) or {}
            self._column_cache[cache_key] = int(row.get("cnt") or 0) > 0
        return self._column_cache[cache_key]

    def _now(self) -> str:
        return datetime.now().strftime("%Y-%m-%d %H:%M:%S")
