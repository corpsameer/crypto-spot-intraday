import json
from datetime import datetime
from decimal import Decimal
from typing import Any

from cryptospot.db import execute, fetch_all, fetch_one
from cryptospot.health import write_health_log
from cryptospot.logger import get_logger
from cryptospot.settings import get_setting

SERVICE_NAME = "scan_scoring_engine"
logger = get_logger(__name__)


DEFAULTS = {
    "scoring.weight_15m_momentum": 15,
    "scoring.weight_1h_momentum": 15,
    "scoring.weight_volume_spike": 25,
    "scoring.weight_breakout_near_high": 15,
    "scoring.weight_liquidity_spread": 15,
    "scoring.weight_relative_strength_btc": 10,
    "scoring.weight_market_context": 5,
    "scoring.max_risk_penalty": -20,
    "scanner.watchlist_score_threshold": 70,
    "scanner.strong_score_threshold": 80,
}


def json_default(value: Any):
    if isinstance(value, Decimal):
        return float(value)
    if isinstance(value, datetime):
        return value.strftime("%Y-%m-%d %H:%M:%S")
    return str(value)


def safe_float(value, default=None):
    if value is None or value == "":
        return default
    try:
        return float(value)
    except (TypeError, ValueError):
        return default


class ScoringEngine:
    def run_for_scan_run(self, scan_run_id: int, limit: int = None) -> dict:
        summary = {
            "scan_run_id": scan_run_id,
            "eligible_scan_results": 0,
            "symbols_processed": 0,
            "scored": 0,
            "watchlist_passed": 0,
            "strong_passed": 0,
            "top_symbol": None,
            "top_score": None,
            "scan_results_updated": 0,
            "scanner_metrics_updated": 0,
            "skipped": 0,
            "errors": [],
        }
        try:
            scan_run = fetch_one("SELECT id, raw_payload FROM scan_runs WHERE id = %s LIMIT 1", (scan_run_id,))
            if not scan_run:
                summary["errors"].append(f"scan_run_id={scan_run_id} not found")
                self._write_health("error", f"scan_run_id={scan_run_id} not found", summary)
                return summary

            settings = self._load_settings()
            market_context = self._resolve_market_context(scan_run)
            scan_results = self._load_eligible_scan_results(scan_run_id)
            summary["eligible_scan_results"] = len(scan_results)
            if limit is not None:
                scan_results = scan_results[: max(int(limit), 0)]

            for row in scan_results:
                symbol = row.get("coindcx_symbol")
                try:
                    if not row.get("scanner_metric_id"):
                        summary["skipped"] += 1
                        summary["errors"].append(f"{symbol}: missing_scanner_metric_id")
                        continue
                    scored = self._score_row(row, settings, market_context)
                    metric_updated = self._update_scanner_metric(row["scanner_metric_id"], scored)
                    result_updated = self._update_scan_result(row["scan_result_id"], scored)
                    summary["symbols_processed"] += 1
                    if metric_updated:
                        summary["scanner_metrics_updated"] += 1
                    if result_updated:
                        summary["scan_results_updated"] += 1
                    if metric_updated and result_updated:
                        summary["scored"] += 1
                        if scored["passes_watchlist"]:
                            summary["watchlist_passed"] += 1
                        if scored["passes_strong"]:
                            summary["strong_passed"] += 1
                        if summary["top_score"] is None or scored["final_score"] > summary["top_score"]:
                            summary["top_score"] = scored["final_score"]
                            summary["top_symbol"] = symbol
                    else:
                        summary["skipped"] += 1
                        summary["errors"].append(f"{symbol}: scoring_update_incomplete")
                except Exception as exc:
                    summary["skipped"] += 1
                    summary["errors"].append(f"{symbol}: {exc}")
                    logger.exception("Scan scoring failed for %s", symbol)

            self._update_scan_run_summary(scan_run_id, scan_run, summary)
            status = "warning" if summary["errors"] or summary["skipped"] else "ok"
            message = (
                f"scan_run_id={scan_run_id}, scored={summary['scored']}, "
                f"watchlist_passed={summary['watchlist_passed']}, strong_passed={summary['strong_passed']}, "
                f"top_symbol={summary['top_symbol']}, top_score={summary['top_score']}"
            )
            if status == "warning":
                message += f", skipped={summary['skipped']}, errors={len(summary['errors'])}"
            self._write_health(status, message, summary)
            return summary
        except Exception as exc:
            summary["errors"].append(str(exc))
            self._write_health("error", str(exc), summary)
            return summary

    def _load_settings(self) -> dict:
        settings = {}
        for key, default in DEFAULTS.items():
            value = get_setting(key, default)
            numeric = safe_float(value, default)
            settings[key] = numeric if numeric is not None else default
        if settings["scoring.max_risk_penalty"] > 0:
            settings["scoring.max_risk_penalty"] *= -1
        return settings

    def _load_eligible_scan_results(self, scan_run_id: int) -> list[dict]:
        market_condition_select = ", sr.market_condition" if self._column_exists("scan_results", "market_condition") else ""
        return fetch_all(
            f"""
            SELECT sr.id AS scan_result_id, sr.scan_run_id, sr.spot_symbol_id, sr.scanner_metric_id,
                   sr.coindcx_symbol, sr.api_pair, sr.base_asset, sr.quote_asset,
                   sr.change_5m_percent, sr.change_15m_percent, sr.change_1h_percent, sr.change_4h_percent,
                   sr.volume_spike_15m, sr.volume_spike_1h, sr.quote_volume_24h, sr.spread_percent,
                   sr.orderbook_depth_usdt, sr.slippage_estimate_percent, sr.distance_from_24h_high_percent,
                   sr.candle_close_strength, sr.upper_wick_percent, sr.lower_wick_percent,
                   sr.relative_strength_vs_btc, sr.overextension_risk, sr.risk_penalty,
                   sr.final_score, sr.score_label{market_condition_select}
            FROM scan_results sr
            WHERE sr.scan_run_id = %s AND sr.status = 'metrics_calculated' AND sr.scanner_metric_id IS NOT NULL
            ORDER BY sr.quote_volume_24h DESC, sr.change_1h_percent DESC, sr.id ASC
            """,
            (scan_run_id,),
        )

    def _column_exists(self, table: str, column: str) -> bool:
        try:
            row = fetch_one(
                "SELECT COUNT(*) AS cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s",
                (table, column),
            )
            return bool(row and int(row.get("cnt") or 0))
        except Exception:
            return False

    def _resolve_market_context(self, scan_run: dict) -> dict:
        raw = self._loads(scan_run.get("raw_payload"))
        market_condition = (((raw or {}).get("market_context") or {}).get("market_condition"))
        if market_condition:
            return {"source": "scan_run_raw_payload", "market_condition": str(market_condition).lower()}
        latest = fetch_one("SELECT market_condition FROM market_snapshots ORDER BY snapshot_time DESC, id DESC LIMIT 1")
        if latest and latest.get("market_condition"):
            return {"source": "latest_market_snapshot", "market_condition": str(latest.get("market_condition")).lower()}
        return {"source": "unknown", "market_condition": "unknown"}

    def _score_row(self, row: dict, settings: dict, market_context: dict) -> dict:
        weights = {
            "momentum_15m": settings["scoring.weight_15m_momentum"],
            "momentum_1h": settings["scoring.weight_1h_momentum"],
            "volume_spike": settings["scoring.weight_volume_spike"],
            "breakout_near_high": settings["scoring.weight_breakout_near_high"],
            "liquidity_spread": settings["scoring.weight_liquidity_spread"],
            "relative_strength_btc": settings["scoring.weight_relative_strength_btc"],
            "market_context": settings["scoring.weight_market_context"],
        }
        notes = []
        c15 = safe_float(row.get("change_15m_percent")); c1h = safe_float(row.get("change_1h_percent"))
        vs15 = safe_float(row.get("volume_spike_15m")); vs1h = safe_float(row.get("volume_spike_1h"))
        spread = safe_float(row.get("spread_percent")); rel = safe_float(row.get("relative_strength_vs_btc"))
        if c15 is None: notes.append("missing_change_15m")
        if c1h is None: notes.append("missing_change_1h")
        if vs15 is None and vs1h is None: notes.append("missing_volume_spike")
        if spread is None: notes.append("missing_spread")
        if rel is None: notes.append("missing_relative_strength")
        if market_context.get("market_condition") in (None, "unknown"): notes.append("missing_market_context")

        components = {
            "momentum_15m": self._linear_positive(c15, weights["momentum_15m"], 5),
            "momentum_1h": self._linear_positive(c1h, weights["momentum_1h"], 10),
            "volume_spike": self._volume_score(vs15, vs1h, weights["volume_spike"]),
            "breakout_near_high": self._breakout_score(row, weights["breakout_near_high"]),
            "liquidity_spread": self._liquidity_score(spread, safe_float(row.get("slippage_estimate_percent")), weights["liquidity_spread"]),
            "relative_strength_btc": self._linear_positive(rel, weights["relative_strength_btc"], 5),
            "market_context": self._market_context_score(market_context.get("market_condition"), weights["market_context"]),
        }
        components = {key: round(value, 2) for key, value in components.items()}
        risk_penalty = self._risk_penalty(row, settings["scoring.max_risk_penalty"])
        base_score = round(sum(components.values()), 2)
        final_score = round(min(max(base_score + risk_penalty, 0), 100), 2)
        watchlist = settings["scanner.watchlist_score_threshold"]
        strong = settings["scanner.strong_score_threshold"]
        label = "strong" if final_score >= strong else "watchlist" if final_score >= watchlist else "weak"
        breakdown = {
            "weights": weights,
            "components": components,
            "risk_penalty": risk_penalty,
            "base_score": base_score,
            "final_score": final_score,
            "score_label": label,
            "thresholds": {"watchlist": watchlist, "strong": strong},
            "market_context": market_context,
            "notes": notes,
        }
        return {
            "final_score": final_score,
            "score_label": label,
            "score_breakdown": breakdown,
            "risk_penalty": risk_penalty,
            "passes_watchlist": final_score >= watchlist,
            "passes_strong": final_score >= strong,
        }

    def _linear_positive(self, value, weight, full_at):
        if value is None or value <= 0:
            return 0
        if value >= full_at:
            return weight
        return weight * (value / full_at)

    def _volume_score(self, spike15, spike1h, weight):
        values = [v for v in (spike15, spike1h) if v is not None]
        if not values:
            return 0
        best = max(values)
        if best <= 1:
            return 0
        if best >= 5:
            return weight
        return weight * ((best - 1) / 4)

    def _breakout_score(self, row, weight):
        distance = safe_float(row.get("distance_from_24h_high_percent"))
        close = safe_float(row.get("candle_close_strength"))
        score = 0
        if distance is not None:
            if distance <= 1: score += weight * 0.70
            elif distance <= 3: score += weight * 0.50
            elif distance <= 5: score += weight * 0.25
        if close is not None:
            if close >= 75: score += weight * 0.30
            elif close >= 60: score += weight * 0.15
        return min(score, weight)

    def _liquidity_score(self, spread, slippage, weight):
        if spread is None: score = weight * 0.40
        elif spread <= 0.1: score = weight
        elif spread <= 0.25: score = weight * 0.80
        elif spread <= 0.5: score = weight * 0.40
        else: score = 0
        if slippage is not None:
            if slippage > 1.0: score *= 0.40
            elif slippage > 0.5: score *= 0.70
        return max(score, 0)

    def _market_context_score(self, condition, weight):
        condition = (condition or "unknown").lower()
        return {"bullish": weight, "neutral": weight * 0.60, "volatile": weight * 0.30, "bearish": 0}.get(condition, weight * 0.30)

    def _risk_penalty(self, row, max_risk_penalty):
        penalty = 0
        overextension = safe_float(row.get("overextension_risk"))
        if overextension is not None:
            penalty += -(abs(max_risk_penalty) * overextension / 100)
        checks = (("upper_wick_percent", 50), ("spread_percent", 0.75), ("slippage_estimate_percent", 1.0), ("change_15m_percent", 10), ("change_1h_percent", 20))
        for key, threshold in checks:
            value = safe_float(row.get(key))
            if value is not None and value >= threshold:
                penalty -= 5
        return round(max(penalty, max_risk_penalty), 2)

    def _update_scan_result(self, scan_result_id: int, scored: dict) -> int:
        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        return execute(
            """
            UPDATE scan_results
            SET status = 'scored', stage = 'scoring', final_score = %s, score_label = %s,
                score_breakdown = %s, risk_penalty = %s, score_passed = %s, updated_at = %s
            WHERE id = %s
            """,
            (scored["final_score"], scored["score_label"], json.dumps(scored["score_breakdown"], separators=(",", ":"), default=json_default), scored["risk_penalty"], 1 if scored["passes_watchlist"] else 0, now, scan_result_id),
        )

    def _update_scanner_metric(self, scanner_metric_id: int, scored: dict) -> int:
        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        return execute(
            """
            UPDATE scanner_metrics
            SET final_score = %s, score_label = %s, risk_penalty = %s,
                passes_watchlist = %s, passes_strong = %s, updated_at = %s
            WHERE id = %s
            """,
            (scored["final_score"], scored["score_label"], scored["risk_penalty"], 1 if scored["passes_watchlist"] else 0, 1 if scored["passes_strong"] else 0, now, scanner_metric_id),
        )

    def _update_scan_run_summary(self, scan_run_id: int, scan_run: dict, summary: dict):
        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        raw = self._loads(scan_run.get("raw_payload")) or {}
        raw["scoring"] = summary
        return execute(
            """
            UPDATE scan_runs
            SET scored_count = %s, top_score = %s, top_symbol = %s, raw_payload = %s, updated_at = %s
            WHERE id = %s
            """,
            (summary["scored"], summary["top_score"], summary["top_symbol"], json.dumps(raw, separators=(",", ":"), default=json_default), now, scan_run_id),
        )

    def _loads(self, value):
        if isinstance(value, dict):
            return value
        if not value:
            return {}
        try:
            return json.loads(value)
        except (TypeError, ValueError):
            return {}

    def _write_health(self, status, message, meta):
        try:
            write_health_log(SERVICE_NAME, status, message, meta)
        except Exception:
            logger.exception("Failed to write scan scoring health log")
