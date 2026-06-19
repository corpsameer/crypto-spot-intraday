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
from cryptospot.trade_expiry_monitor import TradeExpiryMonitor  # noqa: E402


def _default_interval() -> int:
    try:
        return int(get_setting("monitor.active_trade_refresh_seconds", 15) or 15)
    except Exception:
        return 15


def _print_cycle(summary: dict) -> None:
    print(
        "trade_expiry_monitor cycle "
        f"trades_loaded={summary.get('trades_loaded')} "
        f"trades_checked={summary.get('trades_checked')} "
        f"events_created={summary.get('events_created')} "
        f"expired={summary.get('trades_expired')} "
        f"trades_updated={summary.get('trades_updated')} "
        f"skipped={summary.get('skipped')} "
        f"errors={len(summary.get('errors') or [])}"
    )


def main() -> int:
    parser = argparse.ArgumentParser(description="Run the legacy disabled simulated trade expiry monitor loop.")
    parser.add_argument("--interval", type=int, default=None, help="Loop interval in seconds")
    parser.add_argument("--limit", type=int, default=None, help="Ignored legacy limit; simulated trades no longer expire per cycle")
    parser.add_argument("--once", action="store_true", help="Run one cycle and exit")
    args = parser.parse_args()

    interval = args.interval if args.interval is not None else _default_interval()
    interval = max(1, int(interval))
    monitor = TradeExpiryMonitor()

    if args.once:
        summary = monitor.run_once(limit=args.limit)
        print(json.dumps(summary, default=str, indent=2))
        return 0

    print(f"Starting trade expiry monitor loop interval={interval}s limit={args.limit}")
    try:
        while True:
            try:
                _print_cycle(monitor.run_once(limit=args.limit))
            except Exception as exc:
                print(f"trade_expiry_monitor cycle failed: {exc}", file=sys.stderr)
            time.sleep(interval)
    except KeyboardInterrupt:
        print("Stopping trade expiry monitor loop")
        return 0


if __name__ == "__main__":
    sys.exit(main())
