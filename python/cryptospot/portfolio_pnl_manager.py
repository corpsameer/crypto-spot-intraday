from datetime import datetime
from decimal import Decimal, InvalidOperation, ROUND_HALF_UP
from typing import Any, Callable

from cryptospot.db import execute, fetch_all, fetch_one
from cryptospot.settings import get_setting

OPEN_STATUSES = ("active", "tp1_hit", "tp2_hit", "trailing_active")
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


class PortfolioPnlManager:
    def __init__(self, db=None, settings_reader: Callable[[str, Any], Any] = None):
        self.db = db
        self.settings_reader = settings_reader or get_setting

    def calculate_trade_pnl(self, trade: dict, latest_price: float) -> dict:
        latest = _decimal(latest_price)
        allocated_capital = _decimal(trade.get("allocated_capital"))
        quantity = _decimal(trade.get("quantity"))
        entry_price = _decimal(trade.get("entry_price"))
        fees_enabled = _bool(self.settings_reader("portfolio.paper_fees_enabled", False), False)
        fees_amount = _decimal(trade.get("fees_amount")) if fees_enabled else Decimal("0")

        if latest <= 0:
            raise ValueError("latest_price_must_be_positive")
        if allocated_capital <= 0:
            raise ValueError("allocated_capital_required")
        if quantity <= 0:
            raise ValueError("quantity_required")
        if entry_price <= 0:
            raise ValueError("entry_price_required")

        current_value = quantity * latest
        unrealized_pnl_amount = current_value - allocated_capital
        pnl_percent_check = ((latest - entry_price) / entry_price) * Decimal("100")
        net_pnl_amount = unrealized_pnl_amount - fees_amount

        return {
            "current_value": float(_money(current_value)),
            "unrealized_pnl_amount": float(_money(unrealized_pnl_amount)),
            "net_pnl_amount": float(_money(net_pnl_amount)),
            "fees_amount": float(_money(fees_amount)),
            "pnl_percent_check": float(_percent(pnl_percent_check)),
        }

    def update_trade_pnl(self, trade_id: int, pnl: dict) -> None:
        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        execute(
            """
            UPDATE simulated_trades
            SET current_value = %s,
                unrealized_pnl_amount = %s,
                net_pnl_amount = %s,
                fees_amount = COALESCE(fees_amount, %s),
                updated_at = %s
            WHERE id = %s
            """,
            (pnl["current_value"], pnl["unrealized_pnl_amount"], pnl["net_pnl_amount"], pnl["fees_amount"], now, trade_id),
        )

    def refresh_portfolio_equity(self, portfolio_account_id: int) -> dict:
        account = fetch_one("SELECT * FROM portfolio_accounts WHERE id = %s LIMIT 1", (portfolio_account_id,))
        if not account:
            raise ValueError("portfolio_account_missing")

        placeholders = ",".join(["%s"] * len(OPEN_STATUSES))
        row = fetch_one(
            f"""
            SELECT
                COALESCE(SUM(allocated_capital), 0) AS deployed_capital,
                COALESCE(SUM(unrealized_pnl_amount), 0) AS unrealized_pnl
            FROM simulated_trades
            WHERE portfolio_account_id = %s
              AND status IN ({placeholders})
              AND closed_at IS NULL
            """,
            (portfolio_account_id, *OPEN_STATUSES),
        ) or {}

        current_cash = _decimal(account.get("current_cash"))
        starting_capital = _decimal(account.get("starting_capital"))
        deployed_capital = _decimal(row.get("deployed_capital"))
        unrealized_pnl = _decimal(row.get("unrealized_pnl"))
        total_equity = current_cash + unrealized_pnl
        total_return_percent = Decimal("0")
        if starting_capital > 0:
            total_return_percent = ((total_equity - starting_capital) / starting_capital) * Decimal("100")

        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        execute(
            """
            UPDATE portfolio_accounts
            SET deployed_capital = %s,
                unrealized_pnl = %s,
                total_equity = %s,
                total_return_percent = %s,
                updated_at = %s
            WHERE id = %s
            """,
            (
                float(_money(deployed_capital)),
                float(_money(unrealized_pnl)),
                float(_money(total_equity)),
                float(_percent(total_return_percent)),
                now,
                portfolio_account_id,
            ),
        )
        return {
            "portfolio_account_id": portfolio_account_id,
            "deployed_capital": float(_money(deployed_capital)),
            "unrealized_pnl": float(_money(unrealized_pnl)),
            "total_equity": float(_money(total_equity)),
            "total_return_percent": float(_percent(total_return_percent)),
        }

    def refresh_all_active_portfolios(self) -> dict:
        accounts = fetch_all("SELECT id FROM portfolio_accounts WHERE is_active = 1 ORDER BY id ASC")
        refreshed = [self.refresh_portfolio_equity(row["id"]) for row in accounts]
        return {
            "portfolio_accounts_refreshed": len(refreshed),
            "portfolio_unrealized_pnl_total": sum(row["unrealized_pnl"] for row in refreshed),
            "portfolio_total_equity": sum(row["total_equity"] for row in refreshed),
            "accounts": refreshed,
        }
