import argparse
import json
import sys
from importlib.util import find_spec
from pathlib import Path

PYTHON_ROOT = Path(__file__).resolve().parents[1]
if str(PYTHON_ROOT) not in sys.path:
    sys.path.insert(0, str(PYTHON_ROOT))


def parse_csv(value: str | None):
    if not value:
        return None
    return [item.strip() for item in value.split(",") if item.strip()]


def main() -> int:
    parser = argparse.ArgumentParser(description="Run one-shot CoinDCX candle collection.")
    parser.add_argument("--limit", type=int, default=None, help="Limit active symbols processed.")
    parser.add_argument(
        "--base-assets",
        default=None,
        help="Comma-separated base assets to collect, for example: BTC,ETH.",
    )
    parser.add_argument(
        "--timeframes",
        default=None,
        help="Comma-separated timeframe list, for example: 5m,15m,1h",
    )
    args = parser.parse_args()

    if find_spec("mysql") is None or find_spec("mysql.connector") is None:
        print(
            "Candle collection failed: missing mysql-connector-python dependency. "
            "Run `pip install -r requirements.txt` from the python folder.",
            file=sys.stderr,
        )
        return 1

    from cryptospot.candle_collector import CandleCollector

    try:
        collector = CandleCollector()
        summary = collector.run(
            symbols_limit=args.limit,
            timeframes=parse_csv(args.timeframes),
            base_assets=parse_csv(args.base_assets),
        )
        print("Candle collection summary:")
        print(json.dumps(summary, indent=2, default=str))
        return 0
    except Exception as exc:
        print(f"Candle collection failed: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
