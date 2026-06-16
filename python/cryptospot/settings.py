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
