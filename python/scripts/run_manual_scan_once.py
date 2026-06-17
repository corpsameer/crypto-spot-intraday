import argparse
import json
import os
import sys

CURRENT_DIR = os.path.dirname(os.path.abspath(__file__))
PYTHON_DIR = os.path.dirname(CURRENT_DIR)
if PYTHON_DIR not in sys.path:
    sys.path.insert(0, PYTHON_DIR)

from cryptospot.scan_runner import ScanRunner  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(description="Run one manual scan skeleton pass.")
    parser.add_argument("--name", default=None, help="Scan name, for example: Manual Test Scan")
    parser.add_argument("--quote", default=None, help="Quote asset filter, for example: USDT, INR, or ALL")
    parser.add_argument("--limit", type=int, default=None, help="Optional active-symbol limit for testing")
    parser.add_argument("--timeframes", default=None, help="Optional comma-separated candle timeframes for scan candidates, for example: 1m,5m,15m")
    args = parser.parse_args()

    timeframes = [item.strip() for item in args.timeframes.split(",") if item.strip()] if args.timeframes else None
    summary = ScanRunner().run_manual_scan(scan_name=args.name, quote_filter=args.quote, limit=args.limit, timeframes=timeframes)

    print("Manual scan summary")
    print("===================")
    for key in (
        "scan_run_id", "run_uuid", "scan_type", "scan_name", "status", "quote_filter",
        "active_symbols", "ticker_rows_fetched", "matched_symbols", "scan_results_created",
        "market_context", "prefilter", "liquidity", "candles", "metrics", "scoring",
        "duration_seconds", "skipped", "errors",
    ):
        print(f"{key}: {json.dumps(summary.get(key), default=str)}")

    return 1 if summary.get("status") == "failed" else 0


if __name__ == "__main__":
    sys.exit(main())
