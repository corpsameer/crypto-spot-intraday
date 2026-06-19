import json
from datetime import datetime
from decimal import Decimal
from typing import Any

from cryptospot.db import execute, fetch_all, fetch_one, get_connection
from cryptospot.health import write_health_log
from cryptospot.logger import get_logger

SERVICE_NAME = "watchlist_candidate_engine"
logger = get_logger(__name__)


def json_default(value: Any):
    if isinstance(value, Decimal):
        return float(value)
    if isinstance(value, datetime):
        return value.strftime("%Y-%m-%d %H:%M:%S")
    return str(value)


class WatchlistCandidateEngine:
    def run_for_scan_run(self, scan_run_id: int, limit: int = None) -> dict:
        summary = {
            "scan_run_id": scan_run_id,
            "eligible_scan_results": 0,
            "created": 0,
            "updated": 0,
            "linked": 0,
            "skipped": 0,
            "errors": [],
        }
        try:
            scan_run = fetch_one("SELECT id, raw_payload FROM scan_runs WHERE id = %s LIMIT 1", (scan_run_id,))
            if not scan_run:
                summary["errors"].append(f"scan_run_id={scan_run_id} not found")
                self._write_health("error", f"scan_run_id={scan_run_id} not found", summary)
                return summary

            columns = self._load_table_columns("candidate_watchlists")
            if not columns:
                raise RuntimeError("candidate_watchlists table is missing or has no readable columns")

            rows = self._load_selected_scan_results(scan_run_id)
            summary["eligible_scan_results"] = len(rows)
            if limit is not None:
                rows = rows[: max(int(limit), 0)]

            for row in rows:
                symbol = row.get("coindcx_symbol") or row.get("scan_result_id")
                try:
                    existing = self._find_existing_candidate(row.get("spot_symbol_id"), scan_run_id)
                    existing_payload = {}
                    if existing and existing.get("raw_payload"):
                        existing_payload = self._loads(existing.get("raw_payload"))
                    payload = self._build_raw_payload(row, existing_payload)
                    if existing:
                        candidate_id = existing["id"]
                        self._update_candidate(candidate_id, row, payload, columns)
                        summary["updated"] += 1
                    else:
                        candidate_id = self._insert_candidate(row, payload, columns)
                        summary["created"] += 1

                    self._link_scan_result(row["scan_result_id"], candidate_id)
                    summary["linked"] += 1
                except Exception as exc:
                    summary["skipped"] += 1
                    summary["errors"].append(f"{symbol}: {exc}")
                    logger.exception("Watchlist candidate creation failed for %s", symbol)

            self._update_scan_run(scan_run_id, scan_run, summary)
            status = "warning" if summary["errors"] or summary["skipped"] else "ok"
            message = (
                f"scan_run_id={scan_run_id}, created={summary['created']}, "
                f"updated={summary['updated']}, linked={summary['linked']}"
            )
            if status == "warning":
                message += f", skipped={summary['skipped']}, errors={len(summary['errors'])}"
            self._write_health(status, message, summary)
            return summary
        except Exception as exc:
            summary["errors"].append(str(exc))
            self._write_health("error", str(exc), summary)
            return summary

    def _load_selected_scan_results(self, scan_run_id: int) -> list[dict]:
        return fetch_all(
            """
            SELECT
                sr.id AS scan_result_id,
                sr.scan_run_id,
                sr.spot_symbol_id,
                sr.scanner_metric_id,
                sr.candidate_watchlist_id,
                sr.coindcx_symbol,
                sr.api_pair,
                sr.base_asset,
                sr.quote_asset,
                sr.last_price,
                sr.change_5m_percent,
                sr.change_15m_percent,
                sr.change_1h_percent,
                sr.change_4h_percent,
                sr.change_24h_percent,
                sr.volume_spike_15m,
                sr.volume_spike_1h,
                sr.quote_volume_24h,
                sr.spread_percent,
                sr.orderbook_depth_usdt,
                sr.slippage_estimate_percent,
                sr.distance_from_24h_high_percent,
                sr.candle_close_strength,
                sr.upper_wick_percent,
                sr.lower_wick_percent,
                sr.relative_strength_vs_btc,
                sr.overextension_risk,
                sr.risk_penalty,
                sr.final_score,
                sr.score_label,
                sr.score_breakdown,
                sr.score_passed,
                sr.selected_for_watchlist,
                sr.selection_type,
                sr.selection_rank,
                sr.selection_reason,
                sr.raw_payload
            FROM scan_results sr
            WHERE sr.scan_run_id = %s
            AND sr.status = 'scored'
            AND sr.selected_for_watchlist = 1
            AND sr.spot_symbol_id IS NOT NULL
            AND sr.final_score IS NOT NULL
            ORDER BY sr.selection_rank ASC, sr.final_score DESC, sr.id ASC
            """,
            (scan_run_id,),
        )

    def _load_table_columns(self, table: str) -> set[str]:
        rows = fetch_all(
            """
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
            """,
            (table,),
        )
        return {row["COLUMN_NAME"] for row in rows}

    def _find_existing_candidate(self, spot_symbol_id: int, scan_run_id: int):
        if not spot_symbol_id:
            return None
        return fetch_one(
            """
            SELECT id, raw_payload
            FROM candidate_watchlists
            WHERE spot_symbol_id = %s
            AND scan_run_id = %s
            AND status IN ('active', 'refreshed')
            ORDER BY updated_at DESC, id DESC
            LIMIT 1
            """,
            (spot_symbol_id, scan_run_id),
        )

    def _build_raw_payload(self, row: dict, existing_payload: dict = None) -> dict:
        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        history = []
        if isinstance(existing_payload, dict):
            existing_history = existing_payload.get("history")
            if isinstance(existing_history, list):
                history = existing_history[-19:]
        history.append({
            "scan_run_id": row.get("scan_run_id"),
            "scan_result_id": row.get("scan_result_id"),
            "final_score": row.get("final_score"),
            "selection_type": row.get("selection_type"),
            "selection_rank": row.get("selection_rank"),
            "seen_at": now,
        })
        return {
            "source": "scan_result_selection",
            "latest_scan_run_id": row.get("scan_run_id"),
            "latest_scan_result_id": row.get("scan_result_id"),
            "scanner_metric_id": row.get("scanner_metric_id"),
            "selection_type": row.get("selection_type"),
            "selection_rank": row.get("selection_rank"),
            "selection_reason": row.get("selection_reason"),
            "score": {
                "final_score": row.get("final_score"),
                "score_label": row.get("score_label"),
                "score_passed": bool(row.get("score_passed")),
                "score_breakdown": self._loads(row.get("score_breakdown")),
            },
            "metrics": {
                "last_price": row.get("last_price"),
                "change_5m_percent": row.get("change_5m_percent"),
                "change_15m_percent": row.get("change_15m_percent"),
                "change_1h_percent": row.get("change_1h_percent"),
                "change_4h_percent": row.get("change_4h_percent"),
                "change_24h_percent": row.get("change_24h_percent"),
                "volume_spike_15m": row.get("volume_spike_15m"),
                "volume_spike_1h": row.get("volume_spike_1h"),
                "quote_volume_24h": row.get("quote_volume_24h"),
                "spread_percent": row.get("spread_percent"),
                "orderbook_depth_usdt": row.get("orderbook_depth_usdt"),
                "slippage_estimate_percent": row.get("slippage_estimate_percent"),
                "distance_from_24h_high_percent": row.get("distance_from_24h_high_percent"),
                "candle_close_strength": row.get("candle_close_strength"),
                "upper_wick_percent": row.get("upper_wick_percent"),
                "lower_wick_percent": row.get("lower_wick_percent"),
                "relative_strength_vs_btc": row.get("relative_strength_vs_btc"),
                "overextension_risk": row.get("overextension_risk"),
                "risk_penalty": row.get("risk_penalty"),
            },
            "history": history[-20:],
        }

    def _candidate_values(self, row: dict, payload: dict, columns: set[str], include_created: bool) -> dict:
        values = {
            "spot_symbol_id": row.get("spot_symbol_id"),
            "scanner_metric_id": row.get("scanner_metric_id"),
            "coindcx_symbol": row.get("coindcx_symbol"),
            "api_pair": row.get("api_pair"),
            "base_asset": row.get("base_asset"),
            "quote_asset": row.get("quote_asset"),
            "scan_run_id": row.get("scan_run_id"),
            "scan_result_id": row.get("scan_result_id"),
            "status": "active",
            "candidate_type": "watchlist",
            "detected_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
            "last_price": row.get("last_price"),
            "final_score": row.get("final_score"),
            "score": row.get("final_score"),
            "score_label": row.get("score_label"),
            "change_15m_percent": row.get("change_15m_percent"),
            "change_1h_percent": row.get("change_1h_percent"),
            "volume_spike_15m": row.get("volume_spike_15m"),
            "volume_spike_1h": row.get("volume_spike_1h"),
            "spread_percent": row.get("spread_percent"),
            "orderbook_depth_usdt": row.get("orderbook_depth_usdt"),
            "slippage_estimate_percent": row.get("slippage_estimate_percent"),
            "distance_from_24h_high_percent": row.get("distance_from_24h_high_percent"),
            "relative_strength_vs_btc": row.get("relative_strength_vs_btc"),
            "overextension_risk": row.get("overextension_risk"),
            "risk_penalty": row.get("risk_penalty"),
            "selection_type": row.get("selection_type"),
            "selection_rank": row.get("selection_rank"),
            "selection_reason": row.get("selection_reason"),
            "reason": row.get("selection_reason"),
            "raw_payload": json.dumps(payload, separators=(",", ":"), default=json_default),
            "updated_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
        }
        if include_created:
            values["created_at"] = values["updated_at"]
        return {key: value for key, value in values.items() if key in columns}

    def _insert_candidate(self, row: dict, payload: dict, columns: set[str]) -> int:
        values = self._candidate_values(row, payload, columns, include_created=True)
        keys = list(values.keys())
        placeholders = ", ".join(["%s"] * len(keys))
        sql = f"INSERT INTO candidate_watchlists ({', '.join(keys)}) VALUES ({placeholders})"
        connection = get_connection()
        cursor = None
        try:
            cursor = connection.cursor()
            cursor.execute(sql, tuple(values[key] for key in keys))
            connection.commit()
            return cursor.lastrowid
        finally:
            if cursor:
                cursor.close()
            connection.close()

    def _update_candidate(self, candidate_id: int, row: dict, payload: dict, columns: set[str]) -> int:
        values = self._candidate_values(row, payload, columns, include_created=False)
        values.pop("detected_at", None)
        keys = list(values.keys())
        assignments = ", ".join([f"{key} = %s" for key in keys])
        sql = f"UPDATE candidate_watchlists SET {assignments} WHERE id = %s"
        return execute(sql, tuple(values[key] for key in keys) + (candidate_id,))

    def _link_scan_result(self, scan_result_id: int, candidate_id: int) -> int:
        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        return execute(
            """
            UPDATE scan_results
            SET candidate_watchlist_id = %s, candidate_created = 1, updated_at = %s
            WHERE id = %s
            """,
            (candidate_id, now, scan_result_id),
        )

    def _update_scan_run(self, scan_run_id: int, scan_run: dict, summary: dict) -> int:
        raw = self._loads(scan_run.get("raw_payload"))
        raw["watchlist"] = summary
        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        return execute(
            """
            UPDATE scan_runs
            SET watchlist_created_count = %s, raw_payload = %s, updated_at = %s
            WHERE id = %s
            """,
            (summary.get("linked", 0), json.dumps(raw, separators=(",", ":"), default=json_default), now, scan_run_id),
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
            logger.exception("Failed to write watchlist candidate health log")
