import json
from datetime import datetime

from cryptospot.db import execute, fetch_all, fetch_one
from cryptospot.health import write_health_log
from cryptospot.settings import get_settings_by_group

SERVICE_NAME = "prefilter_engine"

DEFAULT_SETTINGS = {
    "scan.max_prefilter_symbols": 50,
    "prefilter.min_24h_quote_volume": 50000.0,
    "prefilter.min_24h_change_percent": 0.0,
    "prefilter.min_abs_24h_change_percent": 1.0,
    "prefilter.min_last_price": 0.0,
    "prefilter.max_spread_percent": 0.5,
    "prefilter.exclude_stablecoins": True,
    "prefilter.stablecoin_assets": ["USDT", "USDC", "BUSD", "DAI", "TUSD", "FDUSD"],
    "prefilter.allowed_quotes": ["USDT"],
}


def _safe_float(value, default=None):
    if value is None or value == "":
        return default
    try:
        return float(str(value).replace(",", "").strip())
    except (TypeError, ValueError):
        return default


def _safe_int(value, default=0):
    try:
        return int(value)
    except (TypeError, ValueError):
        return default


def _safe_bool(value, default=False):
    if value is None:
        return default
    if isinstance(value, bool):
        return value
    normalized = str(value).strip().lower()
    if normalized in ("true", "1", "yes", "y", "on"):
        return True
    if normalized in ("false", "0", "no", "n", "off"):
        return False
    return default


def _safe_list(value, default=None):
    if default is None:
        default = []
    if value is None:
        return list(default)
    if isinstance(value, list):
        return value
    if isinstance(value, tuple):
        return list(value)
    if isinstance(value, str):
        stripped = value.strip()
        if not stripped:
            return []
        try:
            parsed = json.loads(stripped)
            if isinstance(parsed, list):
                return parsed
        except json.JSONDecodeError:
            pass
        return [item.strip() for item in stripped.split(",") if item.strip()]
    return list(default)


def _upper_set(values):
    return {str(value).strip().upper() for value in values if str(value).strip()}


def _priority_value(value):
    number = _safe_float(value)
    return (number is not None, number if number is not None else 0.0)


class PrefilterEngine:
    def apply_to_scan_run(self, scan_run_id: int) -> dict:
        summary = {
            "scan_run_id": scan_run_id,
            "total_discovered": 0,
            "passed": 0,
            "rejected": 0,
            "max_prefilter_symbols": DEFAULT_SETTINGS["scan.max_prefilter_symbols"],
            "settings": {},
            "errors": [],
        }

        try:
            scan_run = fetch_one("SELECT id, raw_payload FROM scan_runs WHERE id = %s LIMIT 1", (scan_run_id,))
            if not scan_run:
                raise ValueError(f"scan_run_id={scan_run_id} does not exist")

            settings = self._load_settings()
            summary["settings"] = settings
            summary["max_prefilter_symbols"] = settings["scan.max_prefilter_symbols"]

            rows = fetch_all(
                """
                SELECT id, coindcx_symbol, base_asset, quote_asset, last_price,
                       change_24h_percent, quote_volume_24h, spread_percent
                FROM scan_results
                WHERE scan_run_id = %s AND status = 'discovered' AND stage = 'ticker'
                ORDER BY id ASC
                """,
                (scan_run_id,),
            )
            summary["total_discovered"] = len(rows)

            passed_rows = []
            rejected_count = 0
            for row in rows:
                rejection_reason = self._first_rejection_reason(row, settings)
                if rejection_reason:
                    if self._update_result(row["id"], False, rejection_reason, f"Rejected: {rejection_reason}", summary):
                        rejected_count += 1
                else:
                    if self._update_result(row["id"], True, None, "Passed basic ticker prefilter", summary):
                        passed_rows.append(row)

            kept_passed_ids = {row["id"] for row in passed_rows}
            max_symbols = settings["scan.max_prefilter_symbols"]
            if max_symbols >= 0 and len(passed_rows) > max_symbols:
                sorted_passed = sorted(
                    passed_rows,
                    key=lambda row: (
                        _priority_value(row.get("quote_volume_24h")),
                        _priority_value(row.get("change_24h_percent")),
                        _priority_value(row.get("last_price")),
                    ),
                    reverse=True,
                )
                keep = sorted_passed[:max_symbols]
                reject = sorted_passed[max_symbols:]
                kept_passed_ids = {row["id"] for row in keep}
                for row in reject:
                    if self._update_result(row["id"], False, "prefilter_limit_exceeded", "Rejected: outside top prefilter limit", summary):
                        rejected_count += 1

            summary["passed"] = len(kept_passed_ids)
            summary["rejected"] = rejected_count
            self._update_scan_run(scan_run, summary)

            if summary["errors"]:
                status = "warning"
                message = f"Prefilter completed with {len(summary['errors'])} row errors for scan_run_id={scan_run_id}"
            else:
                status = "ok"
                message = f"Prefilter completed for scan_run_id={scan_run_id}: passed={summary['passed']}, rejected={summary['rejected']}"
            self._write_health(status, message, summary)
            return summary
        except Exception as exc:
            summary["errors"].append(str(exc))
            self._write_health("error", str(exc), summary)
            return summary

    def _load_settings(self) -> dict:
        try:
            scan_settings = get_settings_by_group("scan")
        except Exception:
            scan_settings = {}
        try:
            prefilter_settings = get_settings_by_group("prefilter")
        except Exception:
            prefilter_settings = {}

        max_symbols = _safe_int(
            scan_settings.get("scan.max_prefilter_symbols", DEFAULT_SETTINGS["scan.max_prefilter_symbols"]),
            DEFAULT_SETTINGS["scan.max_prefilter_symbols"],
        )
        if max_symbols < 0:
            max_symbols = DEFAULT_SETTINGS["scan.max_prefilter_symbols"]

        return {
            "scan.max_prefilter_symbols": max_symbols,
            "prefilter.min_24h_quote_volume": _safe_float(prefilter_settings.get("prefilter.min_24h_quote_volume"), DEFAULT_SETTINGS["prefilter.min_24h_quote_volume"]),
            "prefilter.min_24h_change_percent": _safe_float(prefilter_settings.get("prefilter.min_24h_change_percent"), DEFAULT_SETTINGS["prefilter.min_24h_change_percent"]),
            "prefilter.min_abs_24h_change_percent": _safe_float(prefilter_settings.get("prefilter.min_abs_24h_change_percent"), DEFAULT_SETTINGS["prefilter.min_abs_24h_change_percent"]),
            "prefilter.min_last_price": _safe_float(prefilter_settings.get("prefilter.min_last_price"), DEFAULT_SETTINGS["prefilter.min_last_price"]),
            "prefilter.max_spread_percent": _safe_float(prefilter_settings.get("prefilter.max_spread_percent"), DEFAULT_SETTINGS["prefilter.max_spread_percent"]),
            "prefilter.exclude_stablecoins": _safe_bool(prefilter_settings.get("prefilter.exclude_stablecoins"), DEFAULT_SETTINGS["prefilter.exclude_stablecoins"]),
            "prefilter.stablecoin_assets": list(_upper_set(_safe_list(prefilter_settings.get("prefilter.stablecoin_assets"), DEFAULT_SETTINGS["prefilter.stablecoin_assets"]))),
            "prefilter.allowed_quotes": list(_upper_set(_safe_list(prefilter_settings.get("prefilter.allowed_quotes"), DEFAULT_SETTINGS["prefilter.allowed_quotes"]))),
        }

    def _first_rejection_reason(self, row: dict, settings: dict):
        quote_asset = str(row.get("quote_asset") or "").strip().upper()
        base_asset = str(row.get("base_asset") or "").strip().upper()
        allowed_quotes = _upper_set(settings["prefilter.allowed_quotes"])
        stablecoin_assets = _upper_set(settings["prefilter.stablecoin_assets"])

        if allowed_quotes and quote_asset not in allowed_quotes:
            return "quote_not_allowed"
        if settings["prefilter.exclude_stablecoins"] and base_asset in stablecoin_assets:
            return "stablecoin_base_asset"

        last_price = _safe_float(row.get("last_price"))
        min_last_price = settings["prefilter.min_last_price"]
        if min_last_price is not None and min_last_price > 0 and (last_price is None or last_price < min_last_price):
            return "last_price_below_minimum"

        quote_volume = _safe_float(row.get("quote_volume_24h"))
        min_quote_volume = settings["prefilter.min_24h_quote_volume"]
        if min_quote_volume is not None and min_quote_volume > 0 and (quote_volume is None or quote_volume < min_quote_volume):
            return "quote_volume_below_minimum"

        change = _safe_float(row.get("change_24h_percent"))
        min_change = settings["prefilter.min_24h_change_percent"]
        if min_change is not None and (change is None or change < min_change):
            return "change_24h_below_minimum"

        min_abs_change = settings["prefilter.min_abs_24h_change_percent"]
        if min_abs_change is not None and min_abs_change > 0 and (change is None or abs(change) < min_abs_change):
            return "abs_change_24h_below_minimum"

        spread = _safe_float(row.get("spread_percent"))
        max_spread = settings["prefilter.max_spread_percent"]
        if max_spread is not None and max_spread > 0 and spread is not None and spread > max_spread:
            return "spread_above_maximum"

        return None

    def _update_result(self, result_id, passed, rejection_reason, prefilter_reason, summary):
        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        try:
            execute(
                """
                UPDATE scan_results
                SET prefilter_passed = %s, status = %s, stage = 'prefilter',
                    rejection_reason = %s, prefilter_reason = %s, updated_at = %s
                WHERE id = %s
                """,
                (1 if passed else 0, "prefilter_passed" if passed else "prefilter_rejected", rejection_reason, prefilter_reason, now, result_id),
            )
            return True
        except Exception as exc:
            summary["errors"].append(f"Failed to update scan_result id={result_id}: {exc}")
            return False

    def _update_scan_run(self, scan_run, summary):
        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        raw_payload = {}
        if scan_run.get("raw_payload"):
            try:
                raw_payload = json.loads(scan_run["raw_payload"])
                if not isinstance(raw_payload, dict):
                    raw_payload = {"previous_raw_payload": raw_payload}
            except (TypeError, json.JSONDecodeError):
                raw_payload = {"previous_raw_payload": scan_run.get("raw_payload")}
        raw_payload["prefilter"] = summary
        execute(
            """
            UPDATE scan_runs
            SET prefilter_passed_count = %s, raw_payload = %s, updated_at = %s
            WHERE id = %s
            """,
            (summary["passed"], json.dumps(raw_payload, separators=(",", ":"), default=str), now, summary["scan_run_id"]),
        )

    def _write_health(self, status, message, meta):
        try:
            write_health_log(SERVICE_NAME, status, message, meta)
        except Exception:
            pass
