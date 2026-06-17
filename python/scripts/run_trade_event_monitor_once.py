import argparse
import json
import os
import sys

CURRENT_DIR = os.path.dirname(os.path.abspath(__file__))
PYTHON_DIR = os.path.dirname(CURRENT_DIR)
if PYTHON_DIR not in sys.path:
    sys.path.insert(0, PYTHON_DIR)

from cryptospot.trade_event_monitor import TradeEventMonitor  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(description="Run the TP1/TP2/SL trade event monitor once.")
    parser.add_argument("--limit", type=int, default=None, help="Optional open simulated trade limit")
    args = parser.parse_args()

    try:
        summary = TradeEventMonitor().run_once(limit=args.limit)
    except Exception as exc:
        print(f"Trade event monitor failed: {exc}", file=sys.stderr)
        return 1

    print("Trade event monitor summary")
    print("===========================")
    for key in ("trades_loaded", "trades_checked", "tp1_events_created", "tp2_events_created", "sl_events_created", "trades_closed_sl", "trades_updated", "skipped", "errors"):
        print(f"{key}: {json.dumps(summary.get(key), default=str)}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
