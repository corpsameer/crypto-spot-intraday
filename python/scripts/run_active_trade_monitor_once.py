import argparse
import json
import os
import sys

CURRENT_DIR = os.path.dirname(os.path.abspath(__file__))
PYTHON_DIR = os.path.dirname(CURRENT_DIR)
if PYTHON_DIR not in sys.path:
    sys.path.insert(0, PYTHON_DIR)

from cryptospot.active_trade_monitor import ActiveTradeMonitor  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(description="Run the active simulated trade monitor once.")
    parser.add_argument("--limit", type=int, default=None, help="Optional active simulated trade limit")
    args = parser.parse_args()

    try:
        summary = ActiveTradeMonitor().run_once(limit=args.limit)
    except Exception as exc:
        print(f"Active trade monitor failed: {exc}", file=sys.stderr)
        return 1

    print("Active trade monitor summary")
    print("============================")
    for key in ("trades_loaded", "symbols_checked", "ticker_rows_fetched", "prices_matched", "trades_updated", "skipped", "errors"):
        print(f"{key}: {json.dumps(summary.get(key), default=str)}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
