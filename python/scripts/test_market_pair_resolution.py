#!/usr/bin/env python3
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from cryptospot.coindcx_client import CoinDCXPublicClient
from cryptospot.db import fetch_all, fetch_one
from cryptospot.health import write_health_log

SERVICE_NAME = "market_pair_resolution_test"


def summarize_response(response):
    if isinstance(response, dict):
        keys = list(response.keys())[:10]
        summary = f"dict keys={keys}"
        for key in ("asks", "bids", "data", "candles", "result"):
            value = response.get(key)
            if isinstance(value, (list, dict)):
                summary += f", {key}_count={len(value)}"
        return summary
    if isinstance(response, list):
        return f"list count={len(response)}"
    return type(response).__name__


def main() -> int:
    summary = {
        "active_symbols": 0,
        "with_api_pair": 0,
        "missing_api_pair": 0,
        "tested_api_pair": None,
        "orderbook_ok": False,
        "candles_ok": False,
        "errors": [],
    }

    try:
        counts = fetch_one(
            """
            SELECT
                COUNT(*) AS active_symbols,
                SUM(CASE WHEN api_pair IS NOT NULL AND api_pair != '' THEN 1 ELSE 0 END) AS with_api_pair,
                SUM(CASE WHEN api_pair IS NULL OR api_pair = '' THEN 1 ELSE 0 END) AS missing_api_pair
            FROM spot_symbols
            WHERE is_active = 1
            """
        ) or {}
        summary["active_symbols"] = int(counts.get("active_symbols") or 0)
        summary["with_api_pair"] = int(counts.get("with_api_pair") or 0)
        summary["missing_api_pair"] = int(counts.get("missing_api_pair") or 0)

        rows = fetch_all(
            """
            SELECT id, coindcx_symbol, api_pair, base_asset, quote_asset
            FROM spot_symbols
            WHERE is_active = 1
            ORDER BY coindcx_symbol ASC
            LIMIT 20
            """
        )

        print("Active symbol sample:")
        for row in rows:
            print(
                f"id={row.get('id')} coindcx_symbol={row.get('coindcx_symbol')} "
                f"api_pair={row.get('api_pair')} base={row.get('base_asset')} quote={row.get('quote_asset')}"
            )

        print(
            "Counts: "
            f"active={summary['active_symbols']} with_api_pair={summary['with_api_pair']} "
            f"missing_api_pair={summary['missing_api_pair']}"
        )

        test_row = next((row for row in rows if row.get("api_pair")), None)
        if test_row is None:
            message = "No active symbols with api_pair found."
            print(message)
            write_health_log(SERVICE_NAME, "warning", message, summary)
            return 0

        api_pair = test_row["api_pair"]
        summary["tested_api_pair"] = api_pair
        client = CoinDCXPublicClient()

        try:
            orderbook = client.orderbook(api_pair)
            summary["orderbook_ok"] = True
            print(f"orderbook({api_pair}) -> {summarize_response(orderbook)}")
        except Exception as exc:
            summary["errors"].append(f"orderbook failed: {exc}")
            print(f"orderbook({api_pair}) failed: {exc}")

        try:
            candles = client.candles(api_pair, "1m")
            summary["candles_ok"] = True
            print(f"candles({api_pair}, 1m) -> {summarize_response(candles)}")
        except Exception as exc:
            summary["errors"].append(f"candles failed: {exc}")
            print(f"candles({api_pair}, 1m) failed: {exc}")

        status = "ok" if summary["orderbook_ok"] or summary["candles_ok"] else "warning"
        message = (
            f"tested_api_pair={api_pair}, orderbook_ok={summary['orderbook_ok']}, "
            f"candles_ok={summary['candles_ok']}"
        )
        write_health_log(SERVICE_NAME, status, message, summary)
        return 0
    except Exception as exc:
        summary["errors"].append(str(exc))
        try:
            write_health_log(SERVICE_NAME, "error", str(exc), summary)
        except Exception:
            pass
        print(f"Fatal market pair resolution test error: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
