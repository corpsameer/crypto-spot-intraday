import json
from datetime import datetime, timedelta
from decimal import Decimal
from typing import Any

from cryptospot.db import execute, fetch_all, fetch_one, get_connection
from cryptospot.health import write_health_log
from cryptospot.logger import get_logger

SERVICE_NAME = "metrics_engine"
TIMEFRAME_LIMITS = {
    "1m": 300,
    "5m": 300,
    "15m": 300,
    "1h": 200,
    "4h": 100,
}

logger = get_logger(__name__)


def safe_float(value: Any, default=None):
    if value is None or value == "":
        return default
    try:
        return float(value)
    except (TypeError, ValueError):
        return default


def json_default(value: Any):
    if isinstance(value, Decimal):
        return float(value)
    if isinstance(value, datetime):
        return value.strftime("%Y-%m-%d %H:%M:%S")
    return str(value)


class MetricsEngine:
    def run(self, symbols_limit: int = None, quote_filter: str = None) -> dict:
        summary = {
            "active_symbols": 0,
            "symbols_processed": 0,
            "quote_filter": quote_filter.upper() if quote_filter else None,
            "metrics_inserted": 0,
            "skipped": 0,
            "errors": [],
        }

        try:
            symbols = self._load_active_symbols()
            if quote_filter:
                quote = quote_filter.upper().strip()
                symbols = [row for row in symbols if str(row.get("quote_asset") or "").upper() == quote]
            summary["active_symbols"] = len(symbols)
            if symbols_limit is not None:
                symbols = symbols[: max(symbols_limit, 0)]

            market_snapshot = self._load_latest_market_snapshot()

            for symbol in symbols:
                try:
                    inserted = self._process_symbol(symbol, market_snapshot)
                    if inserted:
                        summary["symbols_processed"] += 1
                        summary["metrics_inserted"] += 1
                    else:
                        summary["skipped"] += 1
                        summary["errors"].append(f"{symbol.get('coindcx_symbol')}: no candle data available")
                except Exception as exc:  # keep one bad symbol from failing the run
                    summary["skipped"] += 1
                    message = f"{symbol.get('coindcx_symbol')}: {exc}"
                    summary["errors"].append(message)
                    logger.exception("Metrics failed for %s", symbol.get("coindcx_symbol"))

            status = "warning" if summary["skipped"] or summary["errors"] else "ok"
            message = (
                f"Metrics engine completed: symbols_processed={summary['symbols_processed']}, "
                f"metrics_inserted={summary['metrics_inserted']}, skipped={summary['skipped']}, "
                f"errors={len(summary['errors'])}"
            )
            write_health_log(SERVICE_NAME, status, message, summary)
            return summary
        except Exception as exc:
            summary["errors"].append(str(exc))
            write_health_log(SERVICE_NAME, "error", str(exc), summary)
            raise


    def run_for_scan_run(self, scan_run_id: int, limit: int = None) -> dict:
        summary = {
            "scan_run_id": scan_run_id,
            "eligible_scan_results": 0,
            "symbols_processed": 0,
            "metrics_inserted": 0,
            "scan_results_updated": 0,
            "skipped": 0,
            "errors": [],
        }

        try:
            scan_run = fetch_one("SELECT id FROM scan_runs WHERE id = %s LIMIT 1", (scan_run_id,))
            if not scan_run:
                summary["errors"].append(f"scan_run_id={scan_run_id} not found")
                write_health_log("scan_metrics_engine", "error", f"scan_run_id={scan_run_id} not found", summary)
                return summary

            scan_results = self._load_eligible_scan_results(scan_run_id)
            summary["eligible_scan_results"] = len(scan_results)
            if limit is not None:
                scan_results = scan_results[: max(int(limit), 0)]

            market_snapshot = self._load_latest_market_snapshot()

            for scan_result in scan_results:
                symbol = scan_result.get("coindcx_symbol")
                scan_result_id = scan_result.get("scan_result_id")
                try:
                    if not scan_result.get("spot_symbol_id"):
                        self._mark_scan_result_failed(scan_result_id, "missing_spot_symbol_id")
                        summary["skipped"] += 1
                        summary["errors"].append(f"{symbol}: missing_spot_symbol_id")
                        continue

                    inserted_id = self._process_scan_result(scan_result, market_snapshot)
                    if inserted_id:
                        summary["symbols_processed"] += 1
                        summary["metrics_inserted"] += 1
                        summary["scan_results_updated"] += 1
                    else:
                        self._mark_scan_result_failed(scan_result_id, "metrics_missing_candles")
                        summary["skipped"] += 1
                        summary["errors"].append(f"{symbol}: metrics_missing_candles")
                except Exception as exc:
                    summary["skipped"] += 1
                    summary["errors"].append(f"{symbol}: {exc}")
                    logger.exception("Scan metrics failed for %s scan_result_id=%s", symbol, scan_result_id)

            self._update_scan_run_metrics_summary(scan_run_id, summary)
            status = "warning" if summary["skipped"] or summary["errors"] else "ok"
            write_health_log(
                "scan_metrics_engine",
                status,
                f"scan_run_id={scan_run_id}, symbols_processed={summary['symbols_processed']}, metrics_inserted={summary['metrics_inserted']}, skipped={summary['skipped']}, errors={len(summary['errors'])}",
                summary,
            )
            return summary
        except Exception as exc:
            summary["errors"].append(str(exc))
            write_health_log("scan_metrics_engine", "error", str(exc), summary)
            return summary

    def _load_eligible_scan_results(self, scan_run_id: int) -> list[dict]:
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
                sr.change_24h_percent AS ticker_change_24h_percent,
                sr.quote_volume_24h,
                sr.spread_percent AS scan_spread_percent,
                sr.orderbook_depth_usdt AS scan_orderbook_depth_usdt,
                sr.slippage_estimate_percent AS scan_slippage_estimate_percent
            FROM scan_results sr
            WHERE sr.scan_run_id = %s
              AND sr.prefilter_passed = 1
              AND sr.status = 'candles_fetched'
            ORDER BY sr.quote_volume_24h DESC, sr.change_24h_percent DESC, sr.id ASC
            """,
            (scan_run_id,),
        )

    def _process_scan_result(self, scan_result: dict, market_snapshot: dict | None) -> int | None:
        candles = {timeframe: self._load_candles(scan_result["spot_symbol_id"], timeframe, limit) for timeframe, limit in TIMEFRAME_LIMITS.items()}
        if not any(candles.values()):
            return None

        symbol = scan_result.get("coindcx_symbol")
        orderbook = self._load_scan_liquidity_metric(scan_result) or self._load_latest_orderbook_metric(symbol)
        latest_candle = self._latest_candle(candles)
        latest_close = safe_float(latest_candle.get("close")) if latest_candle else safe_float(scan_result.get("last_price"))

        change_5m = self._first_available(self._change_percent(candles.get("1m"), timedelta(minutes=5)), self._previous_change(candles.get("5m")))
        change_15m = self._first_available(self._change_percent(candles.get("5m"), timedelta(minutes=15)), self._change_percent(candles.get("1m"), timedelta(minutes=15)))
        change_1h = self._first_available(self._change_percent(candles.get("15m"), timedelta(hours=1)), self._change_percent(candles.get("5m"), timedelta(hours=1)))
        change_4h = self._change_percent(candles.get("1h"), timedelta(hours=4))
        change_24h = self._change_percent(candles.get("1h"), timedelta(hours=24))
        if change_24h is None:
            change_24h = safe_float(scan_result.get("ticker_change_24h_percent"))

        volume_spike_15m = self._first_available(self._volume_spike(candles.get("5m"), 3), self._volume_spike(candles.get("1m"), 15))
        volume_spike_1h = self._first_available(self._volume_spike(candles.get("15m"), 4), self._volume_spike(candles.get("5m"), 12))
        distance_24h = self._first_available(self._distance_from_24h_high(candles.get("1h"), latest_close), self._distance_from_24h_high(candles.get("15m"), latest_close))
        wick_candle = (candles.get("15m") or candles.get("5m") or [None])[-1]
        candle_close_strength, upper_wick, lower_wick = self._candle_shape_metrics(wick_candle)
        relative_strength = self._relative_strength(change_1h, change_24h, market_snapshot)

        spread = self._first_available(safe_float(scan_result.get("scan_spread_percent")), safe_float(orderbook.get("spread_percent")) if orderbook else None)
        bid_price = orderbook.get("bid_price") if orderbook else None
        ask_price = orderbook.get("ask_price") if orderbook else None
        depth = self._first_available(safe_float(scan_result.get("scan_orderbook_depth_usdt")), safe_float(orderbook.get("orderbook_depth_usdt")) if orderbook else None)
        slippage = self._first_available(safe_float(scan_result.get("scan_slippage_estimate_percent")), safe_float(orderbook.get("slippage_estimate_percent")) if orderbook else None)
        overextension_risk = self._overextension_risk(change_15m, change_1h, upper_wick, spread)
        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

        metrics = {
            "change_5m_percent": change_5m,
            "change_15m_percent": change_15m,
            "change_1h_percent": change_1h,
            "change_4h_percent": change_4h,
            "change_24h_percent": change_24h,
            "volume_spike_15m": volume_spike_15m,
            "volume_spike_1h": volume_spike_1h,
            "quote_volume_24h": safe_float(scan_result.get("quote_volume_24h")),
            "spread_percent": spread,
            "bid_price": bid_price,
            "ask_price": ask_price,
            "orderbook_depth_usdt": depth,
            "slippage_estimate_percent": slippage,
            "distance_from_24h_high_percent": distance_24h,
            "candle_close_strength": candle_close_strength,
            "upper_wick_percent": upper_wick,
            "lower_wick_percent": lower_wick,
            "relative_strength_vs_btc": relative_strength,
            "btc_context": self._context_text("BTC", market_snapshot),
            "eth_context": self._context_text("ETH", market_snapshot),
            "market_condition": market_snapshot.get("market_condition") if market_snapshot else None,
            "overextension_risk": overextension_risk,
        }
        raw_payload = {
            "source": "scan_based_metrics",
            "scan_run_id": scan_result.get("scan_run_id"),
            "scan_result_id": scan_result.get("scan_result_id"),
            "source_candle_counts": {tf: len(rows) for tf, rows in candles.items()},
            "latest_close": latest_close,
            "selected_wick_candle": self._compact_candle(wick_candle),
            "liquidity_source": orderbook.get("liquidity_source") if orderbook else ("scan_result" if any(scan_result.get(k) is not None for k in ("scan_spread_percent", "scan_orderbook_depth_usdt", "scan_slippage_estimate_percent")) else None),
            "latest_orderbook_metric": self._metric_ref(orderbook),
            "latest_market_snapshot_time": market_snapshot.get("snapshot_time") if market_snapshot else None,
            "calculated_metrics": metrics,
        }
        metric_id = self._insert_scanner_metric(scan_result, metrics, raw_payload, now)
        self._update_scan_result_with_metrics(scan_result.get("scan_result_id"), metric_id, metrics, raw_payload, now)
        return metric_id

    def _insert_scanner_metric(self, scan_result: dict, metrics: dict, raw_payload: dict, now: str) -> int:
        connection = get_connection()
        cursor = None
        try:
            cursor = connection.cursor()
            cursor.execute(
                """
                INSERT INTO scanner_metrics
                    (spot_symbol_id, coindcx_symbol, metric_time,
                     change_5m_percent, change_15m_percent, change_1h_percent, change_4h_percent, change_24h_percent,
                     volume_spike_15m, volume_spike_1h, quote_volume_24h,
                     spread_percent, bid_price, ask_price, orderbook_depth_usdt, slippage_estimate_percent,
                     distance_from_24h_high_percent, candle_close_strength, upper_wick_percent, lower_wick_percent,
                     relative_strength_vs_btc, btc_context, eth_context, market_condition,
                     overextension_risk, risk_penalty, final_score, score_label,
                     passes_watchlist, passes_strong, rejection_reason, raw_payload, created_at, updated_at)
                VALUES
                    (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                """,
                (
                    scan_result.get("spot_symbol_id"), scan_result.get("coindcx_symbol"), now,
                    metrics["change_5m_percent"], metrics["change_15m_percent"], metrics["change_1h_percent"], metrics["change_4h_percent"], metrics["change_24h_percent"],
                    metrics["volume_spike_15m"], metrics["volume_spike_1h"], metrics["quote_volume_24h"],
                    metrics["spread_percent"], metrics["bid_price"], metrics["ask_price"], metrics["orderbook_depth_usdt"], metrics["slippage_estimate_percent"],
                    metrics["distance_from_24h_high_percent"], metrics["candle_close_strength"], metrics["upper_wick_percent"], metrics["lower_wick_percent"],
                    metrics["relative_strength_vs_btc"], metrics["btc_context"], metrics["eth_context"], metrics["market_condition"],
                    metrics["overextension_risk"], None, None, None, 0, 0, None,
                    json.dumps(raw_payload, separators=(",", ":"), default=json_default), now, now,
                ),
            )
            connection.commit()
            return cursor.lastrowid
        finally:
            if cursor:
                cursor.close()
            connection.close()

    def _update_scan_result_with_metrics(self, scan_result_id: int, metric_id: int, metrics: dict, raw_payload: dict, now: str) -> int:
        return execute(
            """
            UPDATE scan_results
            SET scanner_metric_id = %s, status = 'metrics_calculated', stage = 'metrics',
                change_5m_percent = %s, change_15m_percent = %s, change_1h_percent = %s, change_4h_percent = %s,
                volume_spike_15m = %s, volume_spike_1h = %s, spread_percent = %s,
                orderbook_depth_usdt = %s, slippage_estimate_percent = %s,
                distance_from_24h_high_percent = %s, candle_close_strength = %s,
                upper_wick_percent = %s, lower_wick_percent = %s, relative_strength_vs_btc = %s,
                overextension_risk = %s, risk_penalty = NULL, final_score = NULL, score_label = NULL,
                raw_payload = %s, updated_at = %s
            WHERE id = %s
            """,
            (
                metric_id, metrics["change_5m_percent"], metrics["change_15m_percent"], metrics["change_1h_percent"], metrics["change_4h_percent"],
                metrics["volume_spike_15m"], metrics["volume_spike_1h"], metrics["spread_percent"],
                metrics["orderbook_depth_usdt"], metrics["slippage_estimate_percent"], metrics["distance_from_24h_high_percent"],
                metrics["candle_close_strength"], metrics["upper_wick_percent"], metrics["lower_wick_percent"], metrics["relative_strength_vs_btc"],
                metrics["overextension_risk"], json.dumps({"metrics": raw_payload}, separators=(",", ":"), default=json_default), now, scan_result_id,
            ),
        )

    def _mark_scan_result_failed(self, scan_result_id: int, reason: str):
        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        return execute(
            """
            UPDATE scan_results
            SET status = 'failed', stage = 'metrics', rejection_reason = %s, updated_at = %s
            WHERE id = %s
            """,
            (reason, now, scan_result_id),
        )

    def _update_scan_run_metrics_summary(self, scan_run_id: int, summary: dict):
        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        return execute(
            """
            UPDATE scan_runs
            SET metrics_calculated_count = %s, raw_payload = %s, updated_at = %s
            WHERE id = %s
            """,
            (summary.get("scan_results_updated", 0), json.dumps({"metrics": summary}, separators=(",", ":"), default=json_default), now, scan_run_id),
        )

    def _first_available(self, *values):
        for value in values:
            if value is not None:
                return value
        return None

    def _load_active_symbols(self) -> list[dict]:
        return fetch_all(
            """
            SELECT id, coindcx_symbol, api_pair, base_asset, quote_asset
            FROM spot_symbols
            WHERE is_active = 1
            ORDER BY coindcx_symbol ASC
            """
        )

    def _process_symbol(self, symbol: dict, market_snapshot: dict | None) -> bool:
        candles = {timeframe: self._load_candles(symbol["id"], timeframe, limit) for timeframe, limit in TIMEFRAME_LIMITS.items()}
        if not any(candles.values()):
            return False

        ticker = self._load_latest_ticker_metric(symbol["coindcx_symbol"])
        orderbook = self._load_latest_orderbook_metric(symbol["coindcx_symbol"])
        latest_candle = self._latest_candle(candles)
        latest_close = safe_float(latest_candle.get("close")) if latest_candle else None

        change_5m = self._first_available(
            self._change_percent(candles.get("1m"), timedelta(minutes=5)),
            self._previous_change(candles.get("5m")),
        )
        change_15m = self._first_available(
            self._change_percent(candles.get("5m"), timedelta(minutes=15)),
            self._change_percent(candles.get("1m"), timedelta(minutes=15)),
        )
        change_1h = self._first_available(
            self._change_percent(candles.get("15m"), timedelta(hours=1)),
            self._change_percent(candles.get("5m"), timedelta(hours=1)),
        )
        change_4h = self._change_percent(candles.get("1h"), timedelta(hours=4))
        change_24h = self._change_percent(candles.get("1h"), timedelta(hours=24))
        if change_24h is None and ticker:
            change_24h = safe_float(ticker.get("change_24h_percent"))

        volume_spike_15m = self._first_available(
            self._volume_spike(candles.get("5m"), 3),
            self._volume_spike(candles.get("1m"), 15),
        )
        volume_spike_1h = self._first_available(
            self._volume_spike(candles.get("15m"), 4),
            self._volume_spike(candles.get("5m"), 12),
        )
        distance_24h = self._first_available(
            self._distance_from_24h_high(candles.get("1h"), latest_close),
            self._distance_from_24h_high(candles.get("15m"), latest_close),
        )

        wick_candle = (candles.get("15m") or candles.get("5m") or [None])[-1]
        candle_close_strength, upper_wick, lower_wick = self._candle_shape_metrics(wick_candle)

        relative_strength = self._relative_strength(change_1h, change_24h, market_snapshot)
        spread = safe_float(orderbook.get("spread_percent")) if orderbook else None
        overextension_risk = self._overextension_risk(change_15m, change_1h, upper_wick, spread)
        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")

        raw_payload = {
            "source_candle_counts": {tf: len(rows) for tf, rows in candles.items()},
            "latest_close": latest_close,
            "selected_wick_candle": self._compact_candle(wick_candle),
            "latest_ticker_metric": self._metric_ref(ticker),
            "latest_orderbook_metric": self._metric_ref(orderbook),
            "latest_market_snapshot_time": market_snapshot.get("snapshot_time") if market_snapshot else None,
            "calculated_metrics": {
                "change_5m_percent": change_5m,
                "change_15m_percent": change_15m,
                "change_1h_percent": change_1h,
                "change_4h_percent": change_4h,
                "change_24h_percent": change_24h,
                "volume_spike_15m": volume_spike_15m,
                "volume_spike_1h": volume_spike_1h,
                "distance_from_24h_high_percent": distance_24h,
                "candle_close_strength": candle_close_strength,
                "upper_wick_percent": upper_wick,
                "lower_wick_percent": lower_wick,
                "relative_strength_vs_btc": relative_strength,
                "overextension_risk": overextension_risk,
            },
        }

        execute(
            """
            INSERT INTO scanner_metrics
                (spot_symbol_id, coindcx_symbol, metric_time,
                 change_5m_percent, change_15m_percent, change_1h_percent, change_4h_percent, change_24h_percent,
                 volume_spike_15m, volume_spike_1h, quote_volume_24h,
                 spread_percent, bid_price, ask_price, orderbook_depth_usdt, slippage_estimate_percent,
                 distance_from_24h_high_percent, candle_close_strength, upper_wick_percent, lower_wick_percent,
                 relative_strength_vs_btc, btc_context, eth_context, market_condition,
                 overextension_risk, risk_penalty, final_score, score_label,
                 passes_watchlist, passes_strong, rejection_reason, raw_payload, created_at, updated_at)
            VALUES
                (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            """,
            (
                symbol.get("id"), symbol.get("coindcx_symbol"), now,
                change_5m, change_15m, change_1h, change_4h, change_24h,
                volume_spike_15m, volume_spike_1h, ticker.get("quote_volume_24h") if ticker else None,
                spread, orderbook.get("bid_price") if orderbook else None, orderbook.get("ask_price") if orderbook else None,
                orderbook.get("orderbook_depth_usdt") if orderbook else None, orderbook.get("slippage_estimate_percent") if orderbook else None,
                distance_24h, candle_close_strength, upper_wick, lower_wick,
                relative_strength, self._context_text("BTC", market_snapshot), self._context_text("ETH", market_snapshot),
                market_snapshot.get("market_condition") if market_snapshot else None,
                overextension_risk, None, None, None, 0, 0, None,
                json.dumps(raw_payload, separators=(",", ":"), default=json_default), now, now,
            ),
        )
        return True

    def _load_candles(self, spot_symbol_id: int, timeframe: str, limit: int) -> list[dict]:
        rows = fetch_all(
            """
            SELECT candle_time, open, high, low, close, volume, quote_volume
            FROM candles
            WHERE spot_symbol_id = %s AND timeframe = %s
            ORDER BY candle_time DESC
            LIMIT %s
            """,
            (spot_symbol_id, timeframe, limit),
        )
        return sorted(rows, key=lambda row: row["candle_time"])

    def _load_latest_ticker_metric(self, symbol: str) -> dict | None:
        return fetch_one(
            """
            SELECT id, metric_time, change_24h_percent, quote_volume_24h
            FROM scanner_metrics
            WHERE coindcx_symbol = %s AND (change_24h_percent IS NOT NULL OR quote_volume_24h IS NOT NULL)
            ORDER BY metric_time DESC, id DESC
            LIMIT 1
            """,
            (symbol,),
        )

    def _load_scan_liquidity_metric(self, scan_result: dict) -> dict | None:
        metric = fetch_one(
            """
            SELECT id, metric_time, spread_percent, bid_price, ask_price, orderbook_depth_usdt, slippage_estimate_percent
            FROM scanner_metrics
            WHERE coindcx_symbol = %s
              AND JSON_UNQUOTE(JSON_EXTRACT(raw_payload, '$.source')) = 'scan_liquidity_refresh'
              AND JSON_EXTRACT(raw_payload, '$.scan_run_id') = %s
              AND JSON_EXTRACT(raw_payload, '$.scan_result_id') = %s
            ORDER BY metric_time DESC, id DESC
            LIMIT 1
            """,
            (scan_result.get("coindcx_symbol"), scan_result.get("scan_run_id"), scan_result.get("scan_result_id")),
        )
        if metric:
            metric["liquidity_source"] = "scan_liquidity_refresh"
            return metric
        if any(scan_result.get(k) is not None for k in ("scan_spread_percent", "scan_orderbook_depth_usdt", "scan_slippage_estimate_percent")):
            return {
                "id": None,
                "metric_time": None,
                "spread_percent": scan_result.get("scan_spread_percent"),
                "bid_price": None,
                "ask_price": None,
                "orderbook_depth_usdt": scan_result.get("scan_orderbook_depth_usdt"),
                "slippage_estimate_percent": scan_result.get("scan_slippage_estimate_percent"),
                "liquidity_source": "scan_result",
            }
        return None

    def _load_latest_orderbook_metric(self, symbol: str) -> dict | None:
        return fetch_one(
            """
            SELECT id, metric_time, spread_percent, bid_price, ask_price, orderbook_depth_usdt, slippage_estimate_percent
            FROM scanner_metrics
            WHERE coindcx_symbol = %s
              AND (bid_price IS NOT NULL OR ask_price IS NOT NULL OR spread_percent IS NOT NULL OR orderbook_depth_usdt IS NOT NULL)
            ORDER BY metric_time DESC, id DESC
            LIMIT 1
            """,
            (symbol,),
        )

    def _load_latest_market_snapshot(self) -> dict | None:
        return fetch_one("SELECT * FROM market_snapshots ORDER BY snapshot_time DESC, id DESC LIMIT 1")

    def _latest_candle(self, candles: dict[str, list[dict]]) -> dict | None:
        all_rows = [row for rows in candles.values() for row in rows]
        return max(all_rows, key=lambda row: row["candle_time"]) if all_rows else None

    def _change_percent(self, rows: list[dict] | None, duration: timedelta):
        if not rows or len(rows) < 2:
            return None
        latest = rows[-1]
        latest_close = safe_float(latest.get("close"))
        if latest_close is None:
            return None
        target_time = latest["candle_time"] - duration
        old = min(rows[:-1], key=lambda row: abs(row["candle_time"] - target_time), default=None)
        old_close = safe_float(old.get("close")) if old else None
        if old_close is None or old_close <= 0:
            return None
        return ((latest_close - old_close) / old_close) * 100

    def _previous_change(self, rows: list[dict] | None):
        if not rows or len(rows) < 2:
            return None
        latest_close = safe_float(rows[-1].get("close"))
        old_close = safe_float(rows[-2].get("close"))
        if old_close is None or old_close <= 0 or latest_close is None:
            return None
        return ((latest_close - old_close) / old_close) * 100

    def _volume_spike(self, rows: list[dict] | None, block_size: int):
        if not rows or len(rows) < block_size * 2:
            return None
        current_block = rows[-block_size:]
        prior = rows[:-block_size]
        blocks = []
        while len(prior) >= block_size and len(blocks) < 10:
            blocks.append(prior[-block_size:])
            prior = prior[:-block_size]
        if not blocks:
            return None
        current_volume = sum(safe_float(row.get("volume"), 0) or 0 for row in current_block)
        baseline = sum(sum(safe_float(row.get("volume"), 0) or 0 for row in block) for block in blocks) / len(blocks)
        if baseline <= 0:
            return None
        return current_volume / baseline

    def _distance_from_24h_high(self, rows: list[dict] | None, latest_close: float | None):
        if not rows or latest_close is None:
            return None
        latest_time = rows[-1]["candle_time"]
        recent = [row for row in rows if row["candle_time"] >= latest_time - timedelta(hours=24)]
        if len(recent) < 2:
            return None
        high = max((safe_float(row.get("high")) for row in recent), default=None)
        if high is None or high <= 0:
            return None
        return ((high - latest_close) / high) * 100

    def _candle_shape_metrics(self, candle: dict | None):
        if not candle:
            return None, None, None
        open_price = safe_float(candle.get("open"))
        high = safe_float(candle.get("high"))
        low = safe_float(candle.get("low"))
        close = safe_float(candle.get("close"))
        if None in (open_price, high, low, close) or high <= low:
            return None, None, None
        price_range = high - low
        close_strength = ((close - low) / price_range) * 100
        upper_wick = ((high - max(open_price, close)) / price_range) * 100
        lower_wick = ((min(open_price, close) - low) / price_range) * 100
        return close_strength, upper_wick, lower_wick

    def _relative_strength(self, change_1h, change_24h, market_snapshot):
        if not market_snapshot:
            return None
        btc_1h = safe_float(market_snapshot.get("btc_change_1h_percent"))
        btc_24h = safe_float(market_snapshot.get("btc_change_24h_percent"))
        if change_1h is not None and btc_1h is not None:
            return change_1h - btc_1h
        if change_24h is not None and btc_24h is not None:
            return change_24h - btc_24h
        return None

    def _overextension_risk(self, change_15m, change_1h, upper_wick, spread):
        risk = 0
        if change_15m is not None and change_15m >= 8:
            risk += 30
        if change_1h is not None and change_1h >= 15:
            risk += 30
        if upper_wick is not None and upper_wick >= 40:
            risk += 20
        if spread is not None and spread >= 0.5:
            risk += 20
        return min(risk, 100)

    def _context_text(self, asset: str, market_snapshot: dict | None):
        if not market_snapshot:
            return None
        change_1h = safe_float(market_snapshot.get(f"{asset.lower()}_change_1h_percent"))
        change_24h = safe_float(market_snapshot.get(f"{asset.lower()}_change_24h_percent"))
        condition = market_snapshot.get("market_condition") or "unknown"
        one_hour = "unavailable" if change_1h is None else f"{change_1h:+.2f}%"
        twenty_four_hour = "unavailable" if change_24h is None else f"{change_24h:+.2f}%"
        if asset.upper() == "BTC":
            return f"BTC 1h {one_hour}, 24h {twenty_four_hour}, condition {condition}"
        return f"{asset} 1h {one_hour}, 24h {twenty_four_hour}"

    def _compact_candle(self, candle: dict | None):
        if not candle:
            return None
        return {key: candle.get(key) for key in ("candle_time", "open", "high", "low", "close", "volume")}

    def _metric_ref(self, metric: dict | None):
        if not metric:
            return None
        return {"id": metric.get("id"), "metric_time": metric.get("metric_time")}
