import argparse
import json
import os
import sys

CURRENT_DIR = os.path.dirname(os.path.abspath(__file__))
PYTHON_DIR = os.path.dirname(CURRENT_DIR)
if PYTHON_DIR not in sys.path:
    sys.path.insert(0, PYTHON_DIR)

from cryptospot.trade_expiry_monitor import TradeExpiryMonitor  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(description="Run the legacy disabled simulated trade expiry monitor once.")
    parser.add_argument("--limit", type=int, default=None, help="Ignored legacy limit; simulated trades no longer expire")
    args = parser.parse_args()

    try:
        summary = TradeExpiryMonitor().run_once(limit=args.limit)
    except Exception as exc:
        print(f"Trade expiry monitor failed: {exc}", file=sys.stderr)
        return 1

    print("Trade expiry monitor summary")
    print("============================")
    for key in ("trades_loaded", "trades_checked", "events_created", "trades_expired", "trades_updated", "skipped", "errors"):
        print(f"{key}: {json.dumps(summary.get(key), default=str)}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
