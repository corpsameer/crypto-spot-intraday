import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from cryptospot.health import write_health_log
from cryptospot.settings import get_setting, get_settings_by_group

SERVICE_NAME = "scan_settings_test"

SETTING_KEYS = [
    "scan.enabled",
    "scan.timezone",
    "scan.scheduled_times",
    "scan.default_quote_filter",
    "scan.max_prefilter_symbols",
    "scan.max_final_candidates",
    "scan.prevent_overlap",
    "scan.fetch_candles_for_candidates",
    "scan.fetch_orderbook_for_candidates",
    "prefilter.min_24h_quote_volume",
    "prefilter.min_abs_24h_change_percent",
    "prefilter.exclude_stablecoins",
    "prefilter.stablecoin_assets",
    "prefilter.allowed_quotes",
    "monitor.candidate_refresh_seconds",
    "monitor.trade_plan_refresh_seconds",
    "monitor.active_trade_refresh_seconds",
    "trade_plan.default_valid_hours",
    "trade_plan.breakout_entry_buffer_percent",
    "trade_plan.default_tp1_percent",
    "trade_plan.default_tp2_percent",
    "trade_plan.default_sl_percent",
]

GROUPS = ["scan", "prefilter", "monitor", "trade_plan"]


def main() -> int:
    try:
        print("Scheduled scan workflow settings")
        print("================================")
        for key in SETTING_KEYS:
            value = get_setting(key)
            print(f"{key}: {value!r} ({type(value).__name__})")

        print("\nGrouped setting counts")
        print("======================")
        group_counts = {}
        for group in GROUPS:
            settings = get_settings_by_group(group)
            group_counts[group] = len(settings)
            print(f"{group}: {group_counts[group]}")

        write_health_log(
            SERVICE_NAME,
            "ok",
            "Scan settings loaded successfully",
            {"groups": group_counts, "settings_checked": len(SETTING_KEYS)},
        )
        return 0
    except Exception as exc:
        message = str(exc)
        print(f"ERROR: {message}", file=sys.stderr)
        try:
            write_health_log(
                SERVICE_NAME,
                "error",
                message,
                {"groups": GROUPS, "settings_checked": len(SETTING_KEYS)},
            )
        except Exception as health_exc:
            print(f"ERROR: failed to write health log: {health_exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
