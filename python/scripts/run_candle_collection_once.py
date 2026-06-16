import argparse
import json
import sys
from pathlib import Path

PYTHON_ROOT = Path(__file__).resolve().parents[1]
if str(PYTHON_ROOT) not in sys.path:
    sys.path.insert(0, str(PYTHON_ROOT))

from cryptospot.candle_collector import CandleCollector  # noqa: E402


def parse_timeframes(value: str | None):
    if not value:
        return None
    return [item.strip() for item in value.split(",") if item.strip()]


def main() -> int:
    parser = argparse.ArgumentParser(description="Run one-shot CoinDCX candle collection.")
    parser.add_argument("--limit", type=int, default=None, help="Limit active symbols processed.")
    parser.add_argument(
        "--timeframes",
        default=None,
        help="Comma-separated timeframe list, for example: 5m,15m,1h",
    )
    args = parser.parse_args()

    try:
        collector = CandleCollector()
        summary = collector.run(
            symbols_limit=args.limit,
            timeframes=parse_timeframes(args.timeframes),
        )
        print("Candle collection summary:")
        print(json.dumps(summary, indent=2, default=str))
        return 0
    except Exception as exc:
        print(f"Candle collection failed: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
