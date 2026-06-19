from datetime import datetime, timedelta
from decimal import Decimal
from typing import Any, Callable

from cryptospot.db import fetch_all, fetch_one
from cryptospot.settings import get_setting

OPEN_TRADE_STATUSES = ("active", "tp1_hit", "tp2_hit", "trailing_active")
PENDING_PLAN_STATUSES = ("pending", "watching", "triggered")

DEFAULTS = {
    "portfolio.enabled": True,
    "portfolio.max_open_trades": 3,
    "portfolio.max_pending_trade_plans": 3,
    "portfolio.max_total_open_opportunities": 3,
    "portfolio.reserve_cash_percent": 10.0,
    "portfolio.min_trade_capital": 10000.0,
    "portfolio.prevent_duplicate_symbol": True,
    "portfolio.symbol_cooldown_hours": 24,
    "portfolio.cooldown_after_sl_hours": 24,
    "portfolio.cooldown_after_win_hours": 12,
    "portfolio.cooldown_after_expiry_hours": 0,
    "portfolio.include_pending_plans_in_capital_check": True,
    "portfolio.allow_multiple_strategies_same_symbol": False,
    "portfolio.watchlist_score_min": 50.0,
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


def _int(value: Any, default: int = 0) -> int:
    try:
        return int(value)
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


def _dt(value: Any):
    if isinstance(value, datetime):
        return value
    if not value:
        return None
    text = str(value)
    for fmt in ("%Y-%m-%d %H:%M:%S", "%Y-%m-%dT%H:%M:%S"):
        try:
            return datetime.strptime(text[:19], fmt)
        except ValueError:
            continue
    return None


class PortfolioGate:
    def __init__(self, db=None, settings_reader: Callable[[str, Any], Any] = None):
        self.db = db
        self.settings_reader = settings_reader or get_setting

    def evaluate_candidate(self, candidate: dict) -> dict:
        try:
            state = self.get_portfolio_state()
            details = dict(state)
            details.pop("account", None)
            account = state.get("account")
            account_id = account.get("id") if account else None

            if not state["settings"]["enabled"]:
                return self._reject("portfolio_disabled", account_id, details)
            if not account:
                return self._reject("portfolio_account_missing", None, details)
            if state["open_trades_count"] >= state["settings"]["max_open_trades"]:
                return self._reject("portfolio_rejected_max_open_trades", account_id, details)
            if state["pending_plans_count"] >= state["settings"]["max_pending_trade_plans"]:
                return self._reject("portfolio_rejected_max_pending_trade_plans", account_id, details)
            if state["total_open_opportunities"] >= state["settings"]["max_total_open_opportunities"]:
                return self._reject("portfolio_rejected_max_total_open_opportunities", account_id, details)

            symbol = candidate.get("coindcx_symbol")
            strategy = candidate.get("entry_strategy")
            if state["settings"]["prevent_duplicate_symbol"] and symbol:
                dup_trade = self._duplicate_open_trade(symbol, strategy, state["settings"]["allow_multiple_strategies_same_symbol"])
                details["duplicate_open_trade_id"] = dup_trade.get("id") if dup_trade else None
                if dup_trade:
                    details["duplicate_symbol"] = True
                    return self._reject("portfolio_rejected_duplicate_symbol_open_trade", account_id, details)
                dup_plan = self._duplicate_pending_plan(symbol, strategy, state["settings"]["allow_multiple_strategies_same_symbol"])
                details["duplicate_pending_plan_id"] = dup_plan.get("id") if dup_plan else None
                if dup_plan:
                    details["duplicate_symbol"] = True
                    return self._reject("portfolio_rejected_duplicate_symbol_pending_plan", account_id, details)

            cooldown = self._cooldown(symbol, state["settings"]) if symbol else None
            if cooldown:
                details.update(cooldown)
                return self._reject("portfolio_rejected_symbol_cooldown", account_id, details)

            if state["available_cash"] < state["settings"]["min_trade_capital"]:
                return self._reject("portfolio_rejected_insufficient_cash", account_id, details)

            score = candidate.get("final_score", candidate.get("score"))
            if score is not None and _float(score, 0.0) < 0:
                return self._reject("portfolio_rejected_low_score_allocation", account_id, details)

            return {"approved": True, "reason": "approved", "portfolio_account_id": account_id, "portfolio_status": "approved", "details": details}
        except Exception as exc:
            return {"approved": False, "reason": "portfolio_rejected_unknown_error", "portfolio_account_id": None, "portfolio_status": "rejected", "details": {"error": str(exc)}}

    def get_portfolio_state(self) -> dict:
        settings = self._settings()
        account = fetch_one("SELECT * FROM portfolio_accounts WHERE is_active = 1 ORDER BY id ASC LIMIT 1")
        open_trades = fetch_one("SELECT COUNT(*) AS cnt FROM simulated_trades WHERE status IN ('active','tp1_hit','tp2_hit','trailing_active') AND closed_at IS NULL") or {}
        pending_plans = fetch_one("""
            SELECT COUNT(*) AS cnt
            FROM trade_plans
            WHERE status IN ('pending','watching','triggered')
              AND converted_at IS NULL
              AND (simulated_trade_id IS NULL OR simulated_trade_id = 0)
              AND capital_released_at IS NULL
              AND COALESCE(portfolio_status, '') NOT IN ('released','rejected')
        """) or {}
        current_cash = _float(account.get("current_cash"), 0.0) if account else 0.0
        reserved_cash = _float(account.get("reserved_cash"), 0.0) if account else 0.0
        available_cash = current_cash - reserved_cash
        open_count = _int(open_trades.get("cnt"), 0)
        pending_count = _int(pending_plans.get("cnt"), 0)
        return {
            "settings": settings,
            "account": account,
            "open_trades_count": open_count,
            "pending_plans_count": pending_count,
            "total_open_opportunities": open_count + pending_count,
            "available_cash": available_cash,
            "required_min_cash": settings["min_trade_capital"],
            "duplicate_symbol": False,
            "cooldown_active": False,
        }

    def _settings(self) -> dict:
        raw = {key.split(".", 1)[1]: self.settings_reader(key, default) for key, default in DEFAULTS.items()}
        return {
            "enabled": _bool(raw["enabled"], True),
            "max_open_trades": _int(raw["max_open_trades"], 3),
            "max_pending_trade_plans": _int(raw["max_pending_trade_plans"], 3),
            "max_total_open_opportunities": _int(raw["max_total_open_opportunities"], 3),
            "reserve_cash_percent": _float(raw["reserve_cash_percent"], 10.0),
            "min_trade_capital": _float(raw["min_trade_capital"], 10000.0),
            "prevent_duplicate_symbol": _bool(raw["prevent_duplicate_symbol"], True),
            "symbol_cooldown_hours": _int(raw["symbol_cooldown_hours"], 24),
            "cooldown_after_sl_hours": _int(raw["cooldown_after_sl_hours"], 24),
            "cooldown_after_win_hours": _int(raw["cooldown_after_win_hours"], 12),
            "cooldown_after_expiry_hours": _int(raw["cooldown_after_expiry_hours"], 0),
            "include_pending_plans_in_capital_check": _bool(raw["include_pending_plans_in_capital_check"], True),
            "allow_multiple_strategies_same_symbol": _bool(raw["allow_multiple_strategies_same_symbol"], False),
            "watchlist_score_min": _float(raw["watchlist_score_min"], 50.0),
        }

    def _duplicate_open_trade(self, symbol: str, strategy: str, allow_multiple: bool):
        sql = "SELECT id FROM simulated_trades WHERE coindcx_symbol = %s AND status IN ('active','tp1_hit','tp2_hit','trailing_active') AND closed_at IS NULL"
        params = [symbol]
        if allow_multiple and strategy:
            sql += " AND entry_strategy = %s"
            params.append(strategy)
        return fetch_one(sql + " ORDER BY id DESC LIMIT 1", tuple(params))

    def _duplicate_pending_plan(self, symbol: str, strategy: str, allow_multiple: bool):
        sql = """
            SELECT id
            FROM trade_plans
            WHERE coindcx_symbol = %s
              AND (
                    status IN ('pending','watching','triggered')
                    OR (portfolio_status = 'capital_reserved' AND (simulated_trade_id IS NULL OR simulated_trade_id = 0))
                  )
              AND converted_at IS NULL
              AND (simulated_trade_id IS NULL OR simulated_trade_id = 0)
              AND capital_released_at IS NULL
              AND COALESCE(portfolio_status, '') NOT IN ('released','rejected')
        """
        params = [symbol]
        if allow_multiple and strategy:
            sql += " AND entry_strategy = %s"
            params.append(strategy)
        return fetch_one(sql + " ORDER BY id DESC LIMIT 1", tuple(params))

    def _cooldown(self, symbol: str, settings: dict):
        rows = fetch_all("""
            SELECT id, status, COALESCE(close_reason, exit_reason) AS close_reason, closed_at, final_pnl_percent, net_pnl_amount, portfolio_account_id
            FROM simulated_trades
            WHERE coindcx_symbol = %s AND closed_at IS NOT NULL
            ORDER BY (portfolio_account_id IS NOT NULL) DESC, closed_at DESC, id DESC
            LIMIT 10
        """, (symbol,))
        for row in rows:
            reason = str(row.get("close_reason") or "").lower()
            status = str(row.get("status") or "").lower()
            if status == "expired" or reason in ("expiry", "expired"):
                continue
            pnl_percent = _float(row.get("final_pnl_percent"), 0.0)
            net_pnl = _float(row.get("net_pnl_amount"), 0.0)
            hours = settings["symbol_cooldown_hours"]
            if "sl" in status or reason in ("sl", "stop_loss", "stop-loss", "stoploss"):
                hours = settings["cooldown_after_sl_hours"]
            elif "trailing" in status or "tp" in status or reason in ("trailing", "trailing_stop", "tp", "tp1", "tp2", "take_profit", "win") or pnl_percent > 0 or net_pnl > 0:
                hours = settings["cooldown_after_win_hours"]
            elif "manual" in status or reason == "manual":
                hours = min(settings["symbol_cooldown_hours"], 12) or 12
            elif pnl_percent < 0 or net_pnl < 0:
                hours = settings["symbol_cooldown_hours"]
            closed_at = _dt(row.get("closed_at"))
            if not closed_at or hours <= 0:
                return None
            cooldown_until = closed_at + timedelta(hours=hours)
            if datetime.now() < cooldown_until:
                return {
                    "cooldown_active": True,
                    "last_trade_id": row.get("id"),
                    "last_closed_at": closed_at.strftime("%Y-%m-%d %H:%M:%S"),
                    "last_close_reason": row.get("close_reason") or row.get("status"),
                    "cooldown_hours": hours,
                    "cooldown_until": cooldown_until.strftime("%Y-%m-%d %H:%M:%S"),
                }
            return None
        return None

    def _reject(self, reason: str, account_id, details: dict) -> dict:
        return {"approved": False, "reason": reason, "portfolio_account_id": account_id, "portfolio_status": "rejected", "details": details}
