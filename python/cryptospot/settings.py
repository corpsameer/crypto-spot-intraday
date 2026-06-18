import json

from cryptospot.db import fetch_all, fetch_one


def _convert_value(value, value_type: str):
    if value is None:
        return None

    normalized_type = (value_type or "string").lower()

    if normalized_type == "integer":
        return int(value)
    if normalized_type == "decimal":
        return float(value)
    if normalized_type == "boolean":
        normalized_value = str(value).strip().lower()
        if normalized_value in ("true", "1", "yes"):
            return True
        if normalized_value in ("false", "0", "no"):
            return False
        return bool(value)
    if normalized_type == "json":
        return json.loads(value)

    return str(value)


def get_setting(key: str, default=None):
    row = fetch_one(
        "SELECT value, value_type FROM app_settings WHERE `key` = %s LIMIT 1",
        (key,),
    )
    if not row:
        return default

    return _convert_value(row.get("value"), row.get("value_type"))


def get_settings_by_group(group: str) -> dict:
    rows = fetch_all(
        "SELECT `key`, value, value_type FROM app_settings WHERE `group` = %s ORDER BY `key`",
        (group,),
    )
    return {row["key"]: _convert_value(row.get("value"), row.get("value_type")) for row in rows}


def get_bool(key: str, default=False) -> bool:
    value = get_setting(key, default)
    if isinstance(value, bool):
        return value
    if value is None:
        return default
    normalized_value = str(value).strip().lower()
    if normalized_value in ("true", "1", "yes"):
        return True
    if normalized_value in ("false", "0", "no"):
        return False
    return bool(value)


def get_int(key: str, default=0) -> int:
    value = get_setting(key, default)
    if value is None:
        return default
    return int(value)


def get_decimal(key: str, default=0.0) -> float:
    value = get_setting(key, default)
    if value is None:
        return default
    return float(value)


def get_json(key: str, default=None):
    value = get_setting(key, default)
    if value is None:
        return default
    if isinstance(value, str):
        return json.loads(value)
    return value


def get_portfolio_settings() -> dict:
    return {
        "enabled": get_bool("portfolio.enabled", True),
        "default_account_name": get_setting("portfolio.default_account_name", "Default INR Portfolio"),
        "currency": get_setting("portfolio.currency", "INR"),
        "starting_capital": get_decimal("portfolio.starting_capital", 100000.0),
        "max_open_trades": get_int("portfolio.max_open_trades", 3),
        "preferred_open_trades": get_int("portfolio.preferred_open_trades", 2),
        "max_pending_trade_plans": get_int("portfolio.max_pending_trade_plans", 3),
        "max_total_open_opportunities": get_int("portfolio.max_total_open_opportunities", 3),
        "reserve_cash_percent": get_decimal("portfolio.reserve_cash_percent", 10.0),
        "min_trade_capital": get_decimal("portfolio.min_trade_capital", 10000.0),
        "max_trade_capital": get_decimal("portfolio.max_trade_capital", 40000.0),
        "strong_score_min": get_decimal("portfolio.strong_score_min", 70.0),
        "watchlist_score_min": get_decimal("portfolio.watchlist_score_min", 50.0),
        "strong_allocation_capital": get_decimal("portfolio.strong_allocation_capital", 40000.0),
        "watchlist_allocation_capital": get_decimal("portfolio.watchlist_allocation_capital", 30000.0),
        "weak_allocation_capital": get_decimal("portfolio.weak_allocation_capital", 20000.0),
        "fallback_allocation_capital": get_decimal("portfolio.fallback_allocation_capital", 15000.0),
        "prevent_duplicate_symbol": get_bool("portfolio.prevent_duplicate_symbol", True),
        "symbol_cooldown_hours": get_int("portfolio.symbol_cooldown_hours", 24),
        "cooldown_after_sl_hours": get_int("portfolio.cooldown_after_sl_hours", 24),
        "cooldown_after_win_hours": get_int("portfolio.cooldown_after_win_hours", 12),
        "cooldown_after_expiry_hours": get_int("portfolio.cooldown_after_expiry_hours", 6),
        "reserve_capital_on_plan_creation": get_bool("portfolio.reserve_capital_on_plan_creation", True),
        "release_capital_on_plan_expiry": get_bool("portfolio.release_capital_on_plan_expiry", True),
        "include_pending_plans_in_capital_check": get_bool("portfolio.include_pending_plans_in_capital_check", True),
        "allow_multiple_strategies_same_symbol": get_bool("portfolio.allow_multiple_strategies_same_symbol", False),
        "paper_fees_enabled": get_bool("portfolio.paper_fees_enabled", False),
        "paper_fee_percent": get_decimal("portfolio.paper_fee_percent", 0.0),
        "monthly_growth_tracking_enabled": get_bool("portfolio.monthly_growth_tracking_enabled", True),
    }
