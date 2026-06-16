import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from cryptospot.coindcx_client import CoinDCXPublicClient
from cryptospot.db import fetch_one
from cryptospot.health import write_health_log


def describe_response(name, response):
    if isinstance(response, list):
        print(f"{name}: list with {len(response)} item(s)")
    elif isinstance(response, dict):
        print(f"{name}: dict with keys {list(response.keys())[:10]}")
    else:
        print(f"{name}: {type(response).__name__}")


def main():
    try:
        client = CoinDCXPublicClient()

        markets = client.markets_details()
        describe_response("markets_details", markets)

        ticker = client.ticker()
        describe_response("ticker", ticker)

        symbol_row = fetch_one(
            "SELECT coindcx_symbol FROM spot_symbols WHERE is_active = 1 ORDER BY coindcx_symbol LIMIT 1"
        )
        if symbol_row:
            pair = symbol_row["coindcx_symbol"]
            print(f"Testing orderbook for DB symbol: {pair}")
            orderbook = client.orderbook(pair)
            describe_response("orderbook", orderbook)
        else:
            print("No active spot_symbols row found; skipped orderbook test.")

        write_health_log("python_coindcx_test", "ok", "CoinDCX public client test successful")
    except Exception as exc:
        print(f"CoinDCX client test failed: {exc}")
        try:
            write_health_log("python_coindcx_test", "error", str(exc))
        except Exception:
            pass
        raise


if __name__ == "__main__":
    main()
