import json
import uuid
from datetime import datetime

from cryptospot.candle_collector import CandleCollector
from cryptospot.coindcx_client import CoinDCXPublicClient
from cryptospot.db import execute, fetch_all, fetch_one, get_connection
from cryptospot.health import write_health_log
from cryptospot.market_context_engine import MarketContextEngine
from cryptospot.metrics_engine import MetricsEngine
from cryptospot.prefilter_engine import PrefilterEngine
from cryptospot.scan_liquidity_collector import ScanLiquidityCollector
from cryptospot.scoring_engine import ScoringEngine
from cryptospot.scan_cycle_expiry_manager import ScanCycleExpiryManager
from cryptospot.trade_plan_generator import TradePlanGenerator
from cryptospot.watchlist_candidate_engine import WatchlistCandidateEngine
from cryptospot.settings import get_settings_by_group

SERVICE_NAME = "scan_runner"


def normalize_symbol(value: str) -> str:
    if value is None:
        return ""
    return str(value).strip().upper().replace("/", "").replace("_", "").replace("-", "")


def safe_float(value, default=None):
    if value is None or value == "":
        return default
    try:
        return float(str(value).replace(",", "").strip())
    except (TypeError, ValueError):
        return default


def extract_ticker_symbol(row: dict) -> str | None:
    if not isinstance(row, dict):
        return None
    for key in ("market", "symbol", "pair", "coindcx_name"):
        symbol = normalize_symbol(row.get(key))
        if symbol:
            return symbol
    return None


def _first_float(row: dict, keys: tuple[str, ...]):
    for key in keys:
        value = safe_float(row.get(key))
        if value is not None:
            return value
    return None


def _derive_quote_volume_24h(row: dict, volume_24h, last_price):
    quote_volume = _first_float(row, ("quote_volume", "quote_volume_24h", "quoteVolume"))
    if quote_volume is not None:
        return quote_volume
    if volume_24h is not None and last_price is not None:
        return volume_24h * last_price
    return None


def normalize_ticker_row(row: dict) -> dict:
    symbol = extract_ticker_symbol(row)
    last_price = _first_float(row, ("last_price", "last", "price", "close"))
    bid_price = _first_float(row, ("bid", "best_bid", "bid_price"))
    ask_price = _first_float(row, ("ask", "best_ask", "ask_price"))
    volume_24h = _first_float(row, ("volume", "volume_24h"))
    quote_volume_24h = _derive_quote_volume_24h(row, volume_24h, last_price)
    spread_percent = None

    if bid_price and ask_price:
        mid = (bid_price + ask_price) / 2
        if mid:
            spread_percent = ((ask_price - bid_price) / mid) * 100

    return {
        "symbol": symbol,
        "last_price": last_price,
        "high_24h": _first_float(row, ("high", "high_24h")),
        "low_24h": _first_float(row, ("low", "low_24h")),
        "volume_24h": volume_24h,
        "quote_volume_24h": quote_volume_24h,
        "change_24h_percent": _first_float(row, ("change_24_hour", "change_24h", "change_24h_percent", "percent_change")),
        "bid_price": bid_price,
        "ask_price": ask_price,
        "spread_percent": spread_percent,
        "raw": row,
    }


class ScanRunner:
    def __init__(self, client: CoinDCXPublicClient = None):
        self.client = client or CoinDCXPublicClient()

    def run_manual_scan(self, scan_name: str = None, quote_filter: str = None, limit: int = None, timeframes: list = None) -> dict:
        started = datetime.now()
        summary = {
            "scan_run_id": None,
            "run_uuid": None,
            "scan_type": "manual",
            "scan_name": scan_name or "Manual Scan",
            "status": "completed",
            "quote_filter": None,
            "active_symbols": 0,
            "ticker_rows_fetched": 0,
            "matched_symbols": 0,
            "scan_results_created": 0,
            "market_context": {
                "enabled": True,
                "market_snapshot_id": None,
                "btc_symbol": None,
                "eth_symbol": None,
                "btc_price": None,
                "eth_price": None,
                "market_condition": None,
                "snapshot_inserted": False,
                "errors": [],
            },
            "prefilter": {
                "total_discovered": 0,
                "passed": 0,
                "rejected": 0,
                "max_prefilter_symbols": 50,
                "errors": [],
            },
            "liquidity": {
                "enabled": False,
                "skipped_reason": "not started",
            },
            "candles": {
                "enabled": False,
                "skipped_reason": "not started",
            },
            "metrics": {
                "eligible_scan_results": 0,
                "symbols_processed": 0,
                "metrics_inserted": 0,
                "scan_results_updated": 0,
                "skipped": 0,
                "errors": [],
            },
            "scoring": {
                "eligible_scan_results": 0,
                "symbols_processed": 0,
                "scored": 0,
                "watchlist_passed": 0,
                "strong_passed": 0,
                "selected_for_watchlist": 0,
                "threshold_selected": 0,
                "fallback_selected": 0,
                "min_required_candidates": 3,
                "min_fallback_candidate_score": 40,
                "max_final_candidates": 10,
                "top_symbol": None,
                "top_score": None,
                "scan_results_updated": 0,
                "scanner_metrics_updated": 0,
                "skipped": 0,
                "errors": [],
            },
            "scan_cycle_expiry": {
                "current_scan_run_id": None,
                "watchlist_candidates_expired": 0,
                "trade_plans_expired": 0,
                "simulated_trades_expired": 0,
                "errors": [],
            },
            "watchlist": {
                "eligible_scan_results": 0,
                "created": 0,
                "updated": 0,
                "linked": 0,
                "skipped": 0,
                "errors": [],
            },
            "trade_plans": {
                "enabled": True,
                "eligible_candidates": 0,
                "plans_created": 0,
                "plans_updated": 0,
                "linked": 0,
                "breakout_plans": 0,
                "pullback_plans": 0,
                "skipped": 0,
                "errors": [],
            },
            "duration_seconds": 0,
            "skipped": 0,
            "errors": [],
        }

        try:
            settings_snapshot = self._load_settings_snapshot()
            resolved_quote_filter = self._resolve_quote_filter(quote_filter, settings_snapshot["scan"].get("scan.default_quote_filter"))
            summary["quote_filter"] = resolved_quote_filter

            if not bool(settings_snapshot["scan"].get("scan.enabled", True)):
                summary["status"] = "disabled"
                message = "Scan workflow is disabled"
                summary["errors"].append(message)
                self._write_health("warning", message, summary)
                return summary

            if not bool(settings_snapshot["scan"].get("scan.allow_manual_scan", True)):
                summary["status"] = "rejected"
                message = "Manual scans are disabled"
                summary["errors"].append(message)
                self._write_health("warning", message, summary)
                return summary

            if bool(settings_snapshot["scan"].get("scan.prevent_overlap", True)):
                running_scan = self._find_running_scan()
                if running_scan:
                    summary["status"] = "skipped"
                    summary["skipped"] = 1
                    message = "Another scan is already running"
                    summary["errors"].append(message)
                    self._write_health("warning", message, {**summary, "running_scan": running_scan})
                    return summary

            run_uuid = str(uuid.uuid4())
            summary["run_uuid"] = run_uuid
            summary["scan_run_id"] = self._create_scan_run(run_uuid, summary["scan_name"], resolved_quote_filter, settings_snapshot)

            summary["scan_cycle_expiry"] = ScanCycleExpiryManager().expire_previous_scan_opportunities(summary["scan_run_id"])
            if summary["scan_cycle_expiry"].get("errors"):
                summary["errors"].extend([f"Scan-cycle expiry: {error}" for error in summary["scan_cycle_expiry"].get("errors", [])])
            self._merge_scan_run_raw_payload(summary["scan_run_id"], {"scan_cycle_expiry": summary["scan_cycle_expiry"]})

            try:
                summary["market_context"] = MarketContextEngine().run(source="scan_runner", scan_run_id=summary["scan_run_id"], refresh_candles=True)
                summary["market_context"]["enabled"] = True
                self._merge_scan_run_raw_payload(summary["scan_run_id"], {"market_context": summary["market_context"]})
                if summary["market_context"].get("errors"):
                    summary["errors"].extend([f"Market context: {error}" for error in summary["market_context"].get("errors", [])])
            except Exception as exc:
                summary["market_context"] = {
                    "enabled": True,
                    "market_snapshot_id": None,
                    "btc_symbol": None,
                    "eth_symbol": None,
                    "btc_price": None,
                    "eth_price": None,
                    "market_condition": None,
                    "snapshot_inserted": False,
                    "refresh_candles": True,
                    "context_candles": {
                        "symbols_processed": 0,
                        "api_calls": 0,
                        "candles_received": 0,
                        "candles_inserted_or_updated": 0,
                        "errors": [],
                    },
                    "errors": [str(exc)],
                }
                summary["errors"].append(f"Market context: {exc}")

            active_symbols = self._load_active_symbols(resolved_quote_filter, limit)
            summary["active_symbols"] = len(active_symbols)

            ticker_rows = self._coerce_ticker_rows(self.client.ticker())
            summary["ticker_rows_fetched"] = len(ticker_rows)

            tickers_by_symbol = {}
            for row in ticker_rows:
                try:
                    normalized = normalize_ticker_row(row)
                    if not normalized["symbol"]:
                        summary["skipped"] += 1
                        continue
                    tickers_by_symbol[normalized["symbol"]] = normalized
                except Exception as exc:
                    summary["skipped"] += 1
                    summary["errors"].append(f"Skipped malformed ticker row: {exc}")

            now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
            for symbol, spot_symbol in active_symbols.items():
                ticker = tickers_by_symbol.get(symbol)
                if ticker is None:
                    continue
                summary["matched_symbols"] += 1
                try:
                    self._insert_scan_result(summary["scan_run_id"], spot_symbol, ticker, now, resolved_quote_filter)
                    summary["scan_results_created"] += 1
                except Exception as exc:
                    summary["errors"].append(f"Failed to insert scan_result for {spot_symbol.get('coindcx_symbol')}: {exc}")

            prefilter_summary = PrefilterEngine().apply_to_scan_run(summary["scan_run_id"])
            summary["prefilter"] = {
                "total_discovered": prefilter_summary.get("total_discovered", 0),
                "passed": prefilter_summary.get("passed", 0),
                "rejected": prefilter_summary.get("rejected", 0),
                "max_prefilter_symbols": prefilter_summary.get("max_prefilter_symbols", 50),
                "errors": prefilter_summary.get("errors", []),
            }
            if prefilter_summary.get("errors"):
                summary["errors"].extend([f"Prefilter: {error}" for error in prefilter_summary.get("errors", [])])

            if bool(settings_snapshot["scan"].get("scan.fetch_orderbook_for_candidates", True)):
                liquidity_summary = ScanLiquidityCollector(client=self.client).run_for_scan_run(summary["scan_run_id"])
                summary["liquidity"] = liquidity_summary
                if liquidity_summary.get("errors"):
                    summary["errors"].extend([f"Liquidity: {error}" for error in liquidity_summary.get("errors", [])])
            else:
                summary["liquidity"] = {
                    "enabled": False,
                    "skipped_reason": "scan.fetch_orderbook_for_candidates disabled",
                }

            if bool(settings_snapshot["scan"].get("scan.fetch_candles_for_candidates", True)):
                candle_summary = CandleCollector(client=self.client).run_for_scan_run(summary["scan_run_id"], timeframes=timeframes)
                candle_summary["enabled"] = True
                summary["candles"] = candle_summary
                if candle_summary.get("errors"):
                    summary["errors"].extend([f"Candles: {error}" for error in candle_summary.get("errors", [])])
            else:
                summary["candles"] = {
                    "enabled": False,
                    "skipped_reason": "scan.fetch_candles_for_candidates disabled",
                }

            metrics_summary = MetricsEngine().run_for_scan_run(summary["scan_run_id"])
            summary["metrics"] = {
                "eligible_scan_results": metrics_summary.get("eligible_scan_results", 0),
                "symbols_processed": metrics_summary.get("symbols_processed", 0),
                "metrics_inserted": metrics_summary.get("metrics_inserted", 0),
                "scan_results_updated": metrics_summary.get("scan_results_updated", 0),
                "skipped": metrics_summary.get("skipped", 0),
                "errors": metrics_summary.get("errors", []),
            }
            if metrics_summary.get("errors"):
                summary["errors"].extend([f"Metrics: {error}" for error in metrics_summary.get("errors", [])])

            scoring_summary = ScoringEngine().run_for_scan_run(summary["scan_run_id"])
            summary["scoring"] = {
                "eligible_scan_results": scoring_summary.get("eligible_scan_results", 0),
                "symbols_processed": scoring_summary.get("symbols_processed", 0),
                "scored": scoring_summary.get("scored", 0),
                "watchlist_passed": scoring_summary.get("watchlist_passed", 0),
                "strong_passed": scoring_summary.get("strong_passed", 0),
                "selected_for_watchlist": scoring_summary.get("selected_for_watchlist", 0),
                "threshold_selected": scoring_summary.get("threshold_selected", 0),
                "fallback_selected": scoring_summary.get("fallback_selected", 0),
                "min_required_candidates": scoring_summary.get("min_required_candidates", 3),
                "min_fallback_candidate_score": scoring_summary.get("min_fallback_candidate_score", 40),
                "max_final_candidates": scoring_summary.get("max_final_candidates", 10),
                "top_symbol": scoring_summary.get("top_symbol"),
                "top_score": scoring_summary.get("top_score"),
                "scan_results_updated": scoring_summary.get("scan_results_updated", 0),
                "scanner_metrics_updated": scoring_summary.get("scanner_metrics_updated", 0),
                "skipped": scoring_summary.get("skipped", 0),
                "errors": scoring_summary.get("errors", []),
            }
            if scoring_summary.get("errors"):
                summary["errors"].extend([f"Scoring: {error}" for error in scoring_summary.get("errors", [])])

            watchlist_summary = WatchlistCandidateEngine().run_for_scan_run(summary["scan_run_id"])
            summary["watchlist"] = {
                "eligible_scan_results": watchlist_summary.get("eligible_scan_results", 0),
                "created": watchlist_summary.get("created", 0),
                "updated": watchlist_summary.get("updated", 0),
                "linked": watchlist_summary.get("linked", 0),
                "skipped": watchlist_summary.get("skipped", 0),
                "errors": watchlist_summary.get("errors", []),
            }
            if watchlist_summary.get("errors"):
                summary["errors"].extend([f"Watchlist: {error}" for error in watchlist_summary.get("errors", [])])

            trade_plan_summary = TradePlanGenerator().run_for_scan_run(summary["scan_run_id"])
            summary["trade_plans"] = {
                "enabled": trade_plan_summary.get("enabled", True),
                "eligible_candidates": trade_plan_summary.get("eligible_candidates", 0),
                "plans_created": trade_plan_summary.get("plans_created", 0),
                "plans_updated": trade_plan_summary.get("plans_updated", 0),
                "linked": trade_plan_summary.get("linked", 0),
                "breakout_plans": trade_plan_summary.get("breakout_plans", 0),
                "pullback_plans": trade_plan_summary.get("pullback_plans", 0),
                "skipped": trade_plan_summary.get("skipped", 0),
                "errors": trade_plan_summary.get("errors", []),
                "portfolio_enabled": trade_plan_summary.get("portfolio_enabled", True),
                "portfolio_approved": trade_plan_summary.get("portfolio_approved", 0),
                "portfolio_rejected": trade_plan_summary.get("portfolio_rejected", 0),
                "portfolio_rejection_reasons": trade_plan_summary.get("portfolio_rejection_reasons", {}),
            }
            if trade_plan_summary.get("errors"):
                summary["errors"].extend([f"Trade plans: {error}" for error in trade_plan_summary.get("errors", [])])

            summary["duration_seconds"] = int((datetime.now() - started).total_seconds())
            self._mark_scan_completed(summary)
            self._write_health(
                "ok",
                f"scan_run_id={summary['scan_run_id']}, matched_symbols={summary['matched_symbols']}, scan_results_created={summary['scan_results_created']}",
                summary,
            )
            return summary
        except Exception as exc:
            summary["status"] = "failed"
            summary["duration_seconds"] = int((datetime.now() - started).total_seconds())
            summary["errors"].append(str(exc))
            if summary.get("scan_run_id"):
                try:
                    self._mark_scan_failed(summary, str(exc))
                except Exception:
                    pass
            self._write_health("error", str(exc), summary)
            return summary

    def _load_settings_snapshot(self) -> dict:
        return {
            "scan": get_settings_by_group("scan"),
            "prefilter": get_settings_by_group("prefilter"),
            "trade_plan": get_settings_by_group("trade_plan"),
        }

    def _resolve_quote_filter(self, cli_quote_filter, default_quote_filter):
        value = cli_quote_filter if cli_quote_filter is not None else default_quote_filter
        if value is None or str(value).strip().upper() == "ALL":
            return None
        return str(value).strip().upper()

    def _find_running_scan(self):
        return fetch_one("""
            SELECT id, run_uuid, started_at
            FROM scan_runs
            WHERE status = 'running'
            ORDER BY started_at DESC
            LIMIT 1
        """)

    def _create_scan_run(self, run_uuid, scan_name, quote_filter, settings_snapshot):
        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        connection = get_connection()
        cursor = None
        try:
            cursor = connection.cursor()
            cursor.execute(
                """
                INSERT INTO scan_runs
                    (run_uuid, scan_type, scan_name, status, started_at, quote_filter,
                     settings_snapshot, notes, created_at, updated_at)
                VALUES
                    (%s, 'manual', %s, 'running', %s, %s, %s, %s, %s, %s)
                """,
                (run_uuid, scan_name, now, quote_filter, json.dumps(settings_snapshot, separators=(",", ":"), default=str), "Manual scan skeleton run", now, now),
            )
            connection.commit()
            return cursor.lastrowid
        finally:
            if cursor:
                cursor.close()
            connection.close()

    def _load_active_symbols(self, quote_filter, limit):
        rows = fetch_all("""
            SELECT id, coindcx_symbol, api_pair, base_asset, quote_asset, is_active
            FROM spot_symbols
            WHERE is_active = 1
            ORDER BY coindcx_symbol ASC
        """)
        filtered = []
        for row in rows:
            if quote_filter and str(row.get("quote_asset") or "").upper() != quote_filter:
                continue
            filtered.append(row)
            if limit is not None and len(filtered) >= int(limit):
                break
        return {normalize_symbol(row.get("coindcx_symbol")): row for row in filtered if normalize_symbol(row.get("coindcx_symbol"))}

    def _coerce_ticker_rows(self, ticker_response) -> list:
        if isinstance(ticker_response, list):
            return [row for row in ticker_response if isinstance(row, dict)]
        if isinstance(ticker_response, dict):
            for key in ("data", "tickers", "ticker", "result"):
                value = ticker_response.get(key)
                if isinstance(value, list):
                    return [row for row in value if isinstance(row, dict)]
        return []

    def _insert_scan_result(self, scan_run_id, spot_symbol, ticker, now, quote_filter):
        raw_payload = {
            "ticker": ticker,
            "scan": {"type": "manual", "quote_filter": quote_filter, "stage": "ticker", "skeleton": True},
        }
        return execute(
            """
            INSERT INTO scan_results
                (scan_run_id, spot_symbol_id, coindcx_symbol, api_pair, base_asset, quote_asset,
                 status, stage, prefilter_passed, score_passed, candidate_created, trade_plan_created,
                 last_price, change_24h_percent, quote_volume_24h, spread_percent, evaluated_at,
                 raw_payload, created_at, updated_at)
            VALUES
                (%s, %s, %s, %s, %s, %s, 'discovered', 'ticker', 0, 0, 0, 0,
                 %s, %s, %s, %s, %s, %s, %s, %s)
            """,
            (
                scan_run_id, spot_symbol.get("id"), spot_symbol.get("coindcx_symbol"), spot_symbol.get("api_pair"),
                spot_symbol.get("base_asset"), spot_symbol.get("quote_asset"), ticker.get("last_price"),
                ticker.get("change_24h_percent"), ticker.get("quote_volume_24h"), ticker.get("spread_percent"),
                now, json.dumps(raw_payload, separators=(",", ":"), default=str), now, now,
            ),
        )

    def _merge_scan_run_raw_payload(self, scan_run_id, values: dict):
        row = fetch_one("SELECT raw_payload FROM scan_runs WHERE id = %s LIMIT 1", (scan_run_id,)) or {}
        try:
            raw_payload = json.loads(row.get("raw_payload") or "{}")
        except (TypeError, ValueError):
            raw_payload = {}
        raw_payload.update(values or {})
        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        return execute(
            "UPDATE scan_runs SET raw_payload = %s, updated_at = %s WHERE id = %s",
            (json.dumps(raw_payload, separators=(",", ":"), default=str), now, scan_run_id),
        )

    def _mark_scan_completed(self, summary):
        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        return execute(
            """
            UPDATE scan_runs
            SET status = 'completed', completed_at = %s, duration_seconds = %s,
                total_active_symbols = %s, ticker_rows_fetched = %s,
                prefilter_passed_count = %s, candles_fetched_count = %s, metrics_calculated_count = %s,
                scored_count = %s, top_score = %s, top_symbol = %s,
                watchlist_created_count = %s, trade_plans_created_count = %s,
                raw_payload = %s, updated_at = %s
            WHERE id = %s
            """,
            (
                now, summary["duration_seconds"], summary["active_symbols"], summary["ticker_rows_fetched"],
                summary.get("prefilter", {}).get("passed", 0),
                self._count_candle_success_results(summary["scan_run_id"]),
                summary.get("metrics", {}).get("scan_results_updated", 0),
                summary.get("scoring", {}).get("scored", 0),
                summary.get("scoring", {}).get("top_score"),
                summary.get("scoring", {}).get("top_symbol"),
                summary.get("watchlist", {}).get("linked", 0),
                summary.get("trade_plans", {}).get("linked", 0),
                json.dumps(summary, separators=(",", ":"), default=str), now, summary["scan_run_id"],
            ),
        )

    def _count_candles_fetched_results(self, scan_run_id):
        row = fetch_one(
            """
            SELECT COUNT(*) AS cnt
            FROM scan_results
            WHERE scan_run_id = %s AND status = 'candles_fetched' AND stage = 'candles'
            """,
            (scan_run_id,),
        )
        return int(row.get("cnt") or 0) if row else 0

    def _count_candle_success_results(self, scan_run_id):
        row = fetch_one(
            """
            SELECT COUNT(*) AS cnt
            FROM scan_results
            WHERE scan_run_id = %s
              AND (
                  (status = 'candles_fetched' AND stage = 'candles')
                  OR (status = 'metrics_calculated' AND stage = 'metrics')
                  OR (status = 'scored' AND stage = 'scoring')
              )
            """,
            (scan_run_id,),
        )
        return int(row.get("cnt") or 0) if row else 0

    def _mark_scan_failed(self, summary, error_message):
        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        return execute(
            """
            UPDATE scan_runs
            SET status = 'failed', completed_at = %s, duration_seconds = %s,
                error_message = %s, raw_payload = %s, updated_at = %s
            WHERE id = %s
            """,
            (now, summary["duration_seconds"], error_message, json.dumps(summary, separators=(",", ":"), default=str), now, summary["scan_run_id"]),
        )

    def _write_health(self, status, message, meta):
        try:
            write_health_log(SERVICE_NAME, status, message, meta)
        except Exception:
            pass
