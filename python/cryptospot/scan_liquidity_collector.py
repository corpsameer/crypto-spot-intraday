import json
import time
from datetime import datetime
from decimal import Decimal
from typing import Any

from cryptospot.coindcx_client import CoinDCXPublicClient
from cryptospot.db import execute, fetch_all, fetch_one, get_connection
from cryptospot.health import write_health_log
from cryptospot.logger import get_logger
from cryptospot.orderbook_collector import (
    API_SLEEP_SECONDS,
    DEFAULT_TARGET_NOTIONAL,
    TOP_LEVELS_LIMIT,
    calculate_liquidity_metrics,
    extract_orderbook,
    safe_float,
)

SERVICE_NAME = "scan_liquidity_collector"
logger = get_logger(__name__)


def json_default(value: Any):
    if isinstance(value, Decimal):
        return float(value)
    if isinstance(value, datetime):
        return value.strftime("%Y-%m-%d %H:%M:%S")
    return str(value)


class ScanLiquidityCollector:
    def __init__(self, client: CoinDCXPublicClient = None):
        self.client = client or CoinDCXPublicClient()

    def run_for_scan_run(self, scan_run_id: int, limit: int = None) -> dict:
        summary = {
            "scan_run_id": scan_run_id,
            "enabled": True,
            "prefilter_passed_results": 0,
            "symbols_processed": 0,
            "api_calls": 0,
            "liquidity_rows_inserted": 0,
            "scan_results_updated": 0,
            "skipped": 0,
            "errors": [],
        }

        try:
            scan_results = self._load_prefilter_passed(scan_run_id)
            summary["prefilter_passed_results"] = len(scan_results)
            if limit is not None:
                scan_results = scan_results[: max(int(limit), 0)]

            if not scan_results:
                write_health_log(SERVICE_NAME, "ok", f"scan_run_id={scan_run_id}, no prefilter-passed candidates for liquidity refresh", summary)
                return summary

            target_notional = DEFAULT_TARGET_NOTIONAL

            for row in scan_results:
                symbol = row.get("coindcx_symbol")
                api_pair = str(row.get("api_pair") or row.get("spot_symbol_api_pair") or "").strip()
                try:
                    if not api_pair:
                        summary["skipped"] += 1
                        summary["errors"].append(f"{symbol}: missing api_pair")
                        continue

                    summary["api_calls"] += 1
                    raw_orderbook = self.client.orderbook(api_pair)
                    orderbook = extract_orderbook(raw_orderbook)
                    bids = orderbook.get("bids") or []
                    asks = orderbook.get("asks") or []
                    if not bids or not asks:
                        summary["skipped"] += 1
                        summary["errors"].append(f"{symbol}: empty or malformed orderbook")
                        continue

                    metrics = calculate_liquidity_metrics(bids, asks, target_notional)
                    if not metrics:
                        summary["skipped"] += 1
                        summary["errors"].append(f"{symbol}: unable to calculate liquidity metrics")
                        continue

                    metric_id = self._insert_liquidity_metric(row, api_pair, bids, asks, metrics, target_notional)
                    summary["liquidity_rows_inserted"] += 1 if metric_id else 0
                    updated = self._update_scan_result_liquidity(row, api_pair, metrics, target_notional)
                    summary["scan_results_updated"] += 1 if updated else 0
                    summary["symbols_processed"] += 1
                except Exception as exc:
                    error = f"{symbol}: {exc}"
                    logger.warning(error)
                    summary["errors"].append(error)
                    summary["skipped"] += 1
                finally:
                    time.sleep(API_SLEEP_SECONDS)

            status = "warning" if summary["errors"] or summary["skipped"] else "ok"
            message = (
                f"scan_run_id={scan_run_id}, symbols_processed={summary['symbols_processed']}, "
                f"liquidity_rows_inserted={summary['liquidity_rows_inserted']}, "
                f"errors={len(summary['errors'])}, skipped={summary['skipped']}"
            )
            write_health_log(SERVICE_NAME, status, message, summary)
            return summary
        except Exception as exc:
            summary["errors"].append(str(exc))
            write_health_log(SERVICE_NAME, "error", str(exc), summary)
            return summary

    def _load_prefilter_passed(self, scan_run_id: int) -> list[dict]:
        return fetch_all(
            """
            SELECT
                sr.id AS scan_result_id,
                sr.scan_run_id,
                sr.spot_symbol_id,
                sr.coindcx_symbol,
                sr.api_pair,
                sr.base_asset,
                sr.quote_asset,
                sr.last_price,
                sr.quote_volume_24h,
                ss.api_pair AS spot_symbol_api_pair
            FROM scan_results sr
            LEFT JOIN spot_symbols ss ON ss.id = sr.spot_symbol_id
            WHERE sr.scan_run_id = %s
              AND sr.prefilter_passed = 1
              AND sr.status IN ('prefilter_passed', 'candles_fetched', 'metrics_calculated')
            ORDER BY sr.quote_volume_24h DESC, sr.change_24h_percent DESC, sr.id ASC
            """,
            (scan_run_id,),
        )

    def _insert_liquidity_metric(self, row: dict, api_pair: str, bids: list, asks: list, metrics: dict, target_notional: float) -> int:
        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        raw_payload = {
            "source": "scan_liquidity_refresh",
            "scan_run_id": row.get("scan_run_id"),
            "scan_result_id": row.get("scan_result_id"),
            "api_pair": api_pair,
            "target_notional": target_notional,
            "orderbook_summary": {"best_bid": bids[0] if bids else None, "best_ask": asks[0] if asks else None, "top_20_bids": bids[:TOP_LEVELS_LIMIT], "top_20_asks": asks[:TOP_LEVELS_LIMIT]},
            "calculation_summary": metrics,
        }
        if metrics.get("slippage_warning"):
            raw_payload["slippage_warning"] = metrics["slippage_warning"]

        connection = get_connection()
        cursor = None
        try:
            cursor = connection.cursor()
            cursor.execute(
                """
                INSERT INTO scanner_metrics
                    (spot_symbol_id, coindcx_symbol, metric_time, bid_price, ask_price,
                     spread_percent, orderbook_depth_usdt, slippage_estimate_percent, quote_volume_24h,
                     final_score, score_label, passes_watchlist, passes_strong, raw_payload, created_at, updated_at)
                VALUES
                    (%s, %s, %s, %s, %s, %s, %s, %s, %s, NULL, NULL, 0, 0, %s, %s, %s)
                """,
                (
                    row.get("spot_symbol_id"), row.get("coindcx_symbol"), now,
                    metrics.get("bid_price"), metrics.get("ask_price"), metrics.get("spread_percent"),
                    metrics.get("orderbook_depth_usdt"), metrics.get("slippage_estimate_percent"),
                    safe_float(row.get("quote_volume_24h")),
                    json.dumps(raw_payload, separators=(",", ":"), default=json_default), now, now,
                ),
            )
            connection.commit()
            return cursor.lastrowid
        finally:
            if cursor:
                cursor.close()
            connection.close()

    def _update_scan_result_liquidity(self, row: dict, api_pair: str, metrics: dict, target_notional: float) -> int:
        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        liquidity_payload = {
            "source": "scan_liquidity_refresh",
            "scan_run_id": row.get("scan_run_id"),
            "scan_result_id": row.get("scan_result_id"),
            "api_pair": api_pair,
            "target_notional": target_notional,
            "spread_percent": metrics.get("spread_percent"),
            "orderbook_depth_usdt": metrics.get("orderbook_depth_usdt"),
            "slippage_estimate_percent": metrics.get("slippage_estimate_percent"),
            "bid_price": metrics.get("bid_price"),
            "ask_price": metrics.get("ask_price"),
        }
        raw_payload = self._merged_scan_result_payload(row.get("scan_result_id"), liquidity_payload)
        return execute(
            """
            UPDATE scan_results
            SET spread_percent = %s,
                orderbook_depth_usdt = %s,
                slippage_estimate_percent = %s,
                raw_payload = %s,
                updated_at = %s
            WHERE id = %s
            """,
            (
                metrics.get("spread_percent"), metrics.get("orderbook_depth_usdt"), metrics.get("slippage_estimate_percent"),
                json.dumps(raw_payload, separators=(",", ":"), default=json_default), now, row.get("scan_result_id"),
            ),
        )

    def _merged_scan_result_payload(self, scan_result_id: int, liquidity_payload: dict) -> dict:
        row = fetch_one("SELECT raw_payload FROM scan_results WHERE id = %s LIMIT 1", (scan_result_id,))
        current = {}
        raw = row.get("raw_payload") if row else None
        if isinstance(raw, dict):
            current = raw
        elif raw:
            try:
                current = json.loads(raw)
            except (TypeError, ValueError):
                current = {"previous_raw_payload": str(raw)}
        current["liquidity"] = liquidity_payload
        return current
