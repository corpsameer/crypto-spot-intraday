import json
import time
from datetime import datetime
from typing import Any

from cryptospot.coindcx_client import CoinDCXPublicClient
from cryptospot.db import execute, fetch_all
from cryptospot.health import write_health_log
from cryptospot.logger import get_logger

SERVICE_NAME = "orderbook_collector"
DEFAULT_TARGET_NOTIONAL = 100.0
API_SLEEP_SECONDS = 0.15
TOP_LEVELS_LIMIT = 20

logger = get_logger(__name__)


def safe_float(value: Any, default=None):
    if value is None or value == "":
        return default

    try:
        return float(str(value).replace(",", "").strip())
    except (TypeError, ValueError):
        return default


def normalize_levels(levels, side: str) -> list:
    normalized = []

    if isinstance(levels, dict):
        iterable = levels.items()
    elif isinstance(levels, (list, tuple)):
        iterable = levels
    else:
        return []

    for row in iterable:
        price = None
        quantity = None

        try:
            if isinstance(row, dict):
                price = _first_float(row, ("price", "p", "rate"))
                quantity = _first_float(row, ("quantity", "qty", "q", "amount", "volume", "size"))
            elif isinstance(row, (list, tuple)) and len(row) >= 2:
                price = safe_float(row[0])
                quantity = safe_float(row[1])
            else:
                continue
        except Exception:
            continue

        if price is None or quantity is None or price <= 0 or quantity <= 0:
            continue

        normalized.append({"price": price, "quantity": quantity})

    reverse = str(side).lower() == "bid"
    return sorted(normalized, key=lambda level: level["price"], reverse=reverse)


def extract_orderbook(raw) -> dict:
    payload = raw
    if isinstance(payload, dict) and isinstance(payload.get("data"), dict):
        payload = payload.get("data")

    if not isinstance(payload, dict):
        return {"bids": [], "asks": []}

    bid_levels = payload.get("bids")
    ask_levels = payload.get("asks")

    if bid_levels is None:
        bid_levels = payload.get("buy") or payload.get("buys")
    if ask_levels is None:
        ask_levels = payload.get("sell") or payload.get("sells")

    return {
        "bids": normalize_levels(bid_levels, "bid"),
        "asks": normalize_levels(ask_levels, "ask"),
    }


def calculate_liquidity_metrics(bids: list, asks: list, target_notional: float) -> dict:
    if not bids or not asks:
        return {}

    bid_price = bids[0]["price"]
    ask_price = asks[0]["price"]
    mid_price = (bid_price + ask_price) / 2
    spread_percent = ((ask_price - bid_price) / mid_price) * 100 if mid_price > 0 else None

    bid_depth_quote = sum(level["price"] * level["quantity"] for level in bids[:TOP_LEVELS_LIMIT])
    ask_depth_quote = sum(level["price"] * level["quantity"] for level in asks[:TOP_LEVELS_LIMIT])

    # Column name is orderbook_depth_usdt from initial schema.
    # For now, value is in the pair quote currency.
    orderbook_depth_usdt = min(bid_depth_quote, ask_depth_quote)

    slippage_estimate_percent = None
    slippage_warning = None
    remaining_notional = target_notional
    total_base_qty = 0.0
    total_quote_spent = 0.0

    for level in asks:
        if remaining_notional <= 0:
            break

        price = level["price"]
        quantity = level["quantity"]
        level_quote_capacity = price * quantity
        quote_to_use = min(remaining_notional, level_quote_capacity)
        base_qty_bought = quote_to_use / price

        total_base_qty += base_qty_bought
        total_quote_spent += quote_to_use
        remaining_notional -= quote_to_use

    if remaining_notional > 0:
        slippage_warning = "insufficient_ask_depth_for_target_notional"
    elif total_base_qty > 0 and ask_price > 0:
        average_fill_price = total_quote_spent / total_base_qty
        slippage_estimate_percent = ((average_fill_price - ask_price) / ask_price) * 100

    return {
        "bid_price": bid_price,
        "ask_price": ask_price,
        "mid_price": mid_price,
        "spread_percent": spread_percent,
        "bid_depth_quote": bid_depth_quote,
        "ask_depth_quote": ask_depth_quote,
        "orderbook_depth_usdt": orderbook_depth_usdt,
        "slippage_estimate_percent": slippage_estimate_percent,
        "slippage_warning": slippage_warning,
    }


def _first_float(row: dict, keys: tuple[str, ...]):
    for key in keys:
        value = safe_float(row.get(key))
        if value is not None:
            return value
    return None


class OrderbookCollector:
    def __init__(self, client: CoinDCXPublicClient = None):
        self.client = client or CoinDCXPublicClient()

    def run(self, symbols_limit: int = None, quote_filter: str = None, target_notional: float = None) -> dict:
        summary = {
            "active_symbols": 0,
            "symbols_processed": 0,
            "quote_filter": quote_filter.upper() if quote_filter else None,
            "api_calls": 0,
            "orderbooks_received": 0,
            "metrics_inserted": 0,
            "skipped": 0,
            "errors": [],
        }

        try:
            target_notional = safe_float(target_notional, DEFAULT_TARGET_NOTIONAL)
            if target_notional is None or target_notional <= 0:
                target_notional = DEFAULT_TARGET_NOTIONAL

            active_symbols = self._load_active_symbols()
            if summary["quote_filter"]:
                active_symbols = [
                    row for row in active_symbols
                    if str(row.get("quote_asset") or "").upper() == summary["quote_filter"]
                ]
            summary["active_symbols"] = len(active_symbols)

            if symbols_limit is not None:
                active_symbols = active_symbols[: max(symbols_limit, 0)]

            if not active_symbols:
                message = "No active spot symbols found for orderbook collection."
                write_health_log(SERVICE_NAME, "warning", message, summary)
                return summary

            for symbol in active_symbols:
                try:
                    api_pair = str(symbol.get("api_pair") or "").strip()
                    coindcx_symbol = symbol.get("coindcx_symbol")
                    if not api_pair:
                        summary["skipped"] += 1
                        summary["errors"].append(f"{coindcx_symbol}: missing api_pair")
                        continue

                    summary["api_calls"] += 1
                    raw_orderbook = self.client.orderbook(api_pair)
                    summary["orderbooks_received"] += 1

                    orderbook = extract_orderbook(raw_orderbook)
                    bids = orderbook["bids"]
                    asks = orderbook["asks"]
                    if not bids or not asks:
                        summary["skipped"] += 1
                        summary["errors"].append(f"{coindcx_symbol}: empty or malformed orderbook")
                        continue

                    metrics = calculate_liquidity_metrics(bids, asks, target_notional)
                    if not metrics:
                        summary["skipped"] += 1
                        summary["errors"].append(f"{coindcx_symbol}: unable to calculate liquidity metrics")
                        continue

                    self._insert_scanner_metric(symbol, bids, asks, metrics, target_notional)
                    summary["metrics_inserted"] += 1
                    summary["symbols_processed"] += 1
                except Exception as exc:
                    error = f"{symbol.get('coindcx_symbol')}: {exc}"
                    logger.warning(error)
                    summary["errors"].append(error)
                    summary["skipped"] += 1
                finally:
                    time.sleep(API_SLEEP_SECONDS)

            status = "warning" if summary["errors"] else "ok"
            message = (
                f"symbols_processed={summary['symbols_processed']}, "
                f"api_calls={summary['api_calls']}, "
                f"orderbooks_received={summary['orderbooks_received']}, "
                f"metrics_inserted={summary['metrics_inserted']}"
            )
            if summary["errors"]:
                message = f"{message}, errors={len(summary['errors'])}"

            write_health_log(SERVICE_NAME, status, message, summary)
            return summary
        except Exception as exc:
            summary["errors"].append(str(exc))
            try:
                write_health_log(SERVICE_NAME, "error", str(exc), summary)
            except Exception:
                pass
            raise

    def _load_active_symbols(self) -> list[dict]:
        return fetch_all(
            """
            SELECT id, coindcx_symbol, api_pair, base_asset, quote_asset
            FROM spot_symbols
            WHERE is_active = 1
            ORDER BY coindcx_symbol ASC
            """
        )

    def _insert_scanner_metric(self, symbol: dict, bids: list, asks: list, metrics: dict, target_notional: float) -> int:
        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        raw_payload = {
            "api_pair": symbol.get("api_pair"),
            "coindcx_symbol": symbol.get("coindcx_symbol"),
            "quote_asset": symbol.get("quote_asset"),
            "best_bid": bids[0] if bids else None,
            "best_ask": asks[0] if asks else None,
            "top_20_bids": bids[:TOP_LEVELS_LIMIT],
            "top_20_asks": asks[:TOP_LEVELS_LIMIT],
            "metrics": metrics,
            "target_notional": target_notional,
        }
        if metrics.get("slippage_warning"):
            raw_payload["slippage_warning"] = metrics["slippage_warning"]

        return execute(
            """
            INSERT INTO scanner_metrics
                (spot_symbol_id, coindcx_symbol, metric_time, spread_percent,
                 bid_price, ask_price, orderbook_depth_usdt, slippage_estimate_percent,
                 passes_watchlist, passes_strong, raw_payload, created_at, updated_at)
            VALUES
                (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            """,
            (
                symbol.get("id"),
                symbol.get("coindcx_symbol"),
                now,
                metrics.get("spread_percent"),
                metrics.get("bid_price"),
                metrics.get("ask_price"),
                metrics.get("orderbook_depth_usdt"),
                metrics.get("slippage_estimate_percent"),
                0,
                0,
                json.dumps(raw_payload, separators=(",", ":"), default=str),
                now,
                now,
            ),
        )
