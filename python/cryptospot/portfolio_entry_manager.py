import json
from datetime import datetime
from decimal import Decimal
from typing import Any, Callable

from cryptospot.db import fetch_one, get_connection
from cryptospot.settings import get_setting


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


class PortfolioEntryManager:
    def __init__(self, db=None, settings_reader: Callable[[str, Any], Any] = None):
        self.db = db
        self.settings_reader = settings_reader or get_setting

    def prepare_simulated_trade_portfolio_fields(self, trade_plan: dict, entry_price: float) -> dict:
        allocated_capital = _float(trade_plan.get("allocated_capital"), 0.0)
        portfolio_account_id = trade_plan.get("portfolio_account_id")
        entry_price = _float(entry_price, 0.0)

        if allocated_capital <= 0:
            raise ValueError("allocated_capital_required")
        if not portfolio_account_id:
            raise ValueError("portfolio_account_id_required")
        if entry_price <= 0:
            raise ValueError("entry_price_required")

        quantity = allocated_capital / entry_price
        entry_value = quantity * entry_price
        paper_fees_enabled = _bool(self.settings_reader("portfolio.paper_fees_enabled", False), False)
        paper_fee_percent = _float(self.settings_reader("portfolio.paper_fee_percent", 0.0), 0.0)
        fees_amount = (allocated_capital * paper_fee_percent / 100) if paper_fees_enabled else 0.0

        return {
            "portfolio_account_id": portfolio_account_id,
            "allocated_capital": round(allocated_capital, 2),
            "allocation_percent": round(_float(trade_plan.get("allocation_percent"), 0.0), 4),
            "quantity": quantity,
            "entry_value": round(entry_value, 2),
            "current_value": round(entry_value, 2),
            "unrealized_pnl_amount": 0.0,
            "realized_pnl_amount": None,
            "fees_amount": round(fees_amount, 2),
            "net_pnl_amount": round(-fees_amount, 2) if paper_fees_enabled else 0.0,
            "capital_released_at": None,
        }

    def move_reserved_to_deployed(self, portfolio_account_id: int, trade_plan_id: int, simulated_trade_id: int, amount: float, payload: dict) -> dict:
        existing_tx = fetch_one(
            "SELECT id FROM portfolio_transactions WHERE simulated_trade_id = %s AND transaction_type = 'trade_entry' LIMIT 1",
            (simulated_trade_id,),
        )
        if existing_tx:
            return {"deployed": False, "created_transaction": False, "reason": "trade_entry_transaction_exists", "transaction_id": existing_tx.get("id")}

        account = fetch_one("SELECT * FROM portfolio_accounts WHERE id = %s LIMIT 1", (portfolio_account_id,))
        if not account:
            return {"deployed": False, "created_transaction": False, "reason": "portfolio_account_missing"}

        amount = _float(amount, 0.0)
        old_reserved = _float(account.get("reserved_cash"), 0.0)
        old_deployed = _float(account.get("deployed_capital"), 0.0)
        if old_reserved + 0.000001 < amount:
            return {
                "deployed": False,
                "created_transaction": False,
                "reason": "portfolio_reserved_cash_insufficient",
                "reserved_cash": old_reserved,
                "amount": amount,
            }

        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        new_reserved = max(old_reserved - amount, 0.0)
        new_deployed = old_deployed + amount
        connection = (payload or {}).pop("_connection", None)
        owns_connection = connection is None
        if connection is None:
            connection = get_connection()
        cursor = None
        try:
            cursor = connection.cursor()
            cursor.execute(
                "UPDATE portfolio_accounts SET reserved_cash = GREATEST(reserved_cash - %s, 0), deployed_capital = deployed_capital + %s, updated_at = %s WHERE id = %s",
                (amount, amount, now, portfolio_account_id),
            )
            raw_payload = {"source": "portfolio_entry_manager", **(payload or {})}
            cursor.execute(
                """
                INSERT INTO portfolio_transactions
                (portfolio_account_id, trade_plan_id, simulated_trade_id, transaction_type, direction, amount,
                 balance_before, balance_after, reserved_before, reserved_after, deployed_before, deployed_after,
                 realized_pnl_before, realized_pnl_after, description, reference_type, reference_id,
                 transaction_time, raw_payload, created_at, updated_at)
                VALUES (%s,%s,%s,'trade_entry','neutral',%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,'simulated_trade',%s,%s,%s,%s,%s)
                """,
                (
                    portfolio_account_id, trade_plan_id, simulated_trade_id, amount,
                    _float(account.get("current_cash"), 0.0), _float(account.get("current_cash"), 0.0),
                    old_reserved, new_reserved,
                    old_deployed, new_deployed,
                    _float(account.get("realized_pnl"), 0.0), _float(account.get("realized_pnl"), 0.0),
                    f"Moved reserved capital to deployed capital for simulated trade {(payload or {}).get('symbol', '')}",
                    simulated_trade_id, now, json.dumps(raw_payload, separators=(",", ":"), default=_json_default), now, now,
                ),
            )
            tx_id = cursor.lastrowid
            if owns_connection:
                connection.commit()
            return {"deployed": True, "created_transaction": True, "reason": "trade_entry", "reserved_before": old_reserved, "reserved_after": new_reserved, "deployed_before": old_deployed, "deployed_after": new_deployed, "transaction_id": tx_id}
        except Exception:
            if owns_connection:
                connection.rollback()
            raise
        finally:
            if cursor:
                cursor.close()
            if owns_connection:
                connection.close()

    def create_trade_entry_transaction(self, portfolio_account: dict, trade_plan_id: int, simulated_trade_id: int, amount: float, payload: dict) -> dict:
        existing_tx = fetch_one(
            "SELECT id FROM portfolio_transactions WHERE simulated_trade_id = %s AND transaction_type = 'trade_entry' LIMIT 1",
            (simulated_trade_id,),
        )
        if existing_tx:
            return {"created_transaction": False, "reason": "trade_entry_transaction_exists", "transaction_id": existing_tx.get("id")}

        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        raw_payload = {"source": "portfolio_entry_manager", **(payload or {})}
        connection = get_connection()
        cursor = None
        try:
            cursor = connection.cursor()
            cursor.execute(
                """
                INSERT INTO portfolio_transactions
                (portfolio_account_id, trade_plan_id, simulated_trade_id, transaction_type, direction, amount,
                 balance_before, balance_after, reserved_before, reserved_after, deployed_before, deployed_after,
                 realized_pnl_before, realized_pnl_after, description, reference_type, reference_id,
                 transaction_time, raw_payload, created_at, updated_at)
                VALUES (%s,%s,%s,'trade_entry','neutral',%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,'simulated_trade',%s,%s,%s,%s,%s)
                """,
                (
                    portfolio_account.get("id"), trade_plan_id, simulated_trade_id, amount,
                    _float(portfolio_account.get("current_cash"), 0.0), _float(portfolio_account.get("current_cash"), 0.0),
                    _float(portfolio_account.get("reserved_cash"), 0.0), _float(portfolio_account.get("reserved_after"), 0.0),
                    _float(portfolio_account.get("deployed_capital"), 0.0), _float(portfolio_account.get("deployed_after"), 0.0),
                    _float(portfolio_account.get("realized_pnl"), 0.0), _float(portfolio_account.get("realized_pnl"), 0.0),
                    f"Moved reserved capital to deployed capital for simulated trade {payload.get('symbol', '')}",
                    simulated_trade_id, now, json.dumps(raw_payload, separators=(",", ":"), default=_json_default), now, now,
                ),
            )
            tx_id = cursor.lastrowid
            connection.commit()
            return {"created_transaction": True, "reason": "trade_entry", "transaction_id": tx_id}
        finally:
            if cursor:
                cursor.close()
            connection.close()
