import argparse
import json
import os
import sys

CURRENT_DIR = os.path.dirname(os.path.abspath(__file__))
PYTHON_DIR = os.path.dirname(CURRENT_DIR)
if PYTHON_DIR not in sys.path:
    sys.path.insert(0, PYTHON_DIR)

from cryptospot.trade_plan_trigger_monitor import TradePlanTriggerMonitor  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(description="Run the trade plan trigger monitor once.")
    parser.add_argument("--limit", type=int, default=None, help="Optional active trade-plan limit")
    args = parser.parse_args()

    try:
        summary = TradePlanTriggerMonitor().run_once(limit=args.limit)
    except Exception as exc:
        print(f"Trade plan trigger monitor failed: {exc}", file=sys.stderr)
        return 1

    print("Trade plan trigger monitor summary")
    print("==================================")
    for key in ("plans_loaded", "symbols_checked", "ticker_rows_fetched", "prices_matched", "plans_updated", "plans_triggered", "plans_expired", "skipped", "errors"):
        print(f"{key}: {json.dumps(summary.get(key), default=str)}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
