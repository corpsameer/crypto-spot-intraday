import argparse
import json
import sys
from pathlib import Path

PYTHON_ROOT = Path(__file__).resolve().parents[1]
if str(PYTHON_ROOT) not in sys.path:
    sys.path.insert(0, str(PYTHON_ROOT))

from cryptospot.orderbook_collector import OrderbookCollector  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(description="Run one-shot CoinDCX orderbook liquidity collection.")
    parser.add_argument("--limit", type=int, default=None, help="Limit active symbols processed after quote filtering.")
    parser.add_argument("--quote", default=None, help="Filter active symbols by quote asset, for example USDT or INR.")
    parser.add_argument("--target-notional", type=float, default=None, help="Quote-currency notional for market-buy slippage estimate.")
    args = parser.parse_args()

    try:
        collector = OrderbookCollector()
        summary = collector.run(
            symbols_limit=args.limit,
            quote_filter=args.quote,
            target_notional=args.target_notional,
        )
        print("Orderbook collection summary:")
        print(json.dumps(summary, indent=2, default=str))
        return 0
    except Exception as exc:
        print(f"Orderbook collection failed: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
