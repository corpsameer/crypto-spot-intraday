import argparse
import json
import sys
from pathlib import Path

PYTHON_ROOT = Path(__file__).resolve().parents[1]
if str(PYTHON_ROOT) not in sys.path:
    sys.path.insert(0, str(PYTHON_ROOT))

from cryptospot.metrics_engine import MetricsEngine  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(description="Run one-shot scanner metrics calculation.")
    parser.add_argument("--limit", type=int, default=None, help="Limit active symbols processed after quote filtering.")
    parser.add_argument("--quote", default=None, help="Filter active symbols by quote asset, for example USDT or INR.")
    args = parser.parse_args()

    try:
        engine = MetricsEngine()
        summary = engine.run(symbols_limit=args.limit, quote_filter=args.quote)
        print("Metrics engine summary:")
        print(json.dumps(summary, indent=2, default=str))
        return 0
    except Exception as exc:
        print(f"Metrics engine failed: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
