import argparse
import json
import os
import sys
import time

CURRENT_DIR = os.path.dirname(os.path.abspath(__file__))
PYTHON_DIR = os.path.dirname(CURRENT_DIR)
if PYTHON_DIR not in sys.path:
    sys.path.insert(0, PYTHON_DIR)

from cryptospot.settings import get_setting  # noqa: E402
from cryptospot.trade_plan_trigger_monitor import TradePlanTriggerMonitor  # noqa: E402


def _default_interval() -> int:
    try:
        return int(get_setting("monitor.trade_plan_refresh_seconds", 30) or 30)
    except Exception:
        return 30


def _print_cycle(summary: dict) -> None:
    print(
        "trade_plan_trigger_monitor cycle "
        f"plans_loaded={summary.get('plans_loaded')} "
        f"symbols_checked={summary.get('symbols_checked')} "
        f"prices_matched={summary.get('prices_matched')} "
        f"updated={summary.get('plans_updated')} "
        f"triggered={summary.get('plans_triggered')} "
        f"expired={summary.get('plans_expired')} "
        f"errors={len(summary.get('errors') or [])}"
    )


def main() -> int:
    parser = argparse.ArgumentParser(description="Run the trade plan trigger monitor loop.")
    parser.add_argument("--interval", type=int, default=None, help="Loop interval in seconds")
    parser.add_argument("--limit", type=int, default=None, help="Optional active trade-plan limit per cycle")
    parser.add_argument("--once", action="store_true", help="Run one cycle and exit")
    args = parser.parse_args()

    interval = args.interval if args.interval is not None else _default_interval()
    interval = max(1, int(interval))
    monitor = TradePlanTriggerMonitor()

    if args.once:
        summary = monitor.run_once(limit=args.limit)
        print(json.dumps(summary, default=str, indent=2))
        return 0

    print(f"Starting trade plan trigger monitor loop interval={interval}s limit={args.limit}")
    while True:
        try:
            _print_cycle(monitor.run_once(limit=args.limit))
        except KeyboardInterrupt:
            print("Stopping trade plan trigger monitor loop")
            return 0
        except Exception as exc:
            print(f"trade_plan_trigger_monitor cycle failed: {exc}", file=sys.stderr)
        time.sleep(interval)


if __name__ == "__main__":
    sys.exit(main())
