import argparse
import json
import os
import sys

CURRENT_DIR = os.path.dirname(os.path.abspath(__file__))
PYTHON_DIR = os.path.dirname(CURRENT_DIR)
if PYTHON_DIR not in sys.path:
    sys.path.insert(0, PYTHON_DIR)

from cryptospot.breakout_entry_simulator import BreakoutEntrySimulator  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(description="Run the breakout entry simulator once.")
    parser.add_argument("--limit", type=int, default=None, help="Optional triggered breakout trade-plan limit")
    args = parser.parse_args()

    try:
        summary = BreakoutEntrySimulator().run_once(limit=args.limit)
    except Exception as exc:
        print(f"Breakout entry simulator failed: {exc}", file=sys.stderr)
        return 1

    print("Breakout entry simulator summary")
    print("================================")
    for key in ("plans_loaded", "trades_created", "events_created", "plans_converted", "skipped", "errors"):
        print(f"{key}: {json.dumps(summary.get(key), default=str)}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
