import json
from datetime import date, datetime
from typing import Any

from cryptospot.db import execute, fetch_all, fetch_one
from cryptospot.health import write_health_log

SERVICE_NAME = "missed_gainer_analyzer"


def json_default(value: Any):
    if isinstance(value, (datetime, date)):
        return value.isoformat()
    return str(value)


def as_float(value: Any, default: float | None = None) -> float | None:
    if value in (None, ""):
        return default
    try:
        return float(value)
    except (TypeError, ValueError):
        return default


class MissedGainerAnalyzer:
    def run(self, analysis_date: str = None, quote_filter: str = "USDT", min_change: float = 10.0, limit: int = 100) -> dict:
        resolved_date = analysis_date or date.today().isoformat()
        quote_filter = (quote_filter or "USDT").strip().upper() or "USDT"
        min_change = float(min_change if min_change is not None else 10.0)
        limit = max(int(limit or 100), 0)
        summary = {
            "analysis_date": resolved_date,
            "quote_filter": quote_filter,
            "min_change": min_change,
            "leaderboard_rows_loaded": 0,
            "rows_analyzed": 0,
            "missed_completely": 0,
            "captured_not_selected": 0,
            "selected_no_trade_plan": 0,
            "trade_plan_not_triggered": 0,
            "simulated_trade_created": 0,
            "rows_upserted": 0,
            "errors": [],
        }
        try:
            rows = self._load_leaderboard_rows(resolved_date, quote_filter, min_change, limit)
            summary["leaderboard_rows_loaded"] = len(rows)
            for row in rows:
                symbol = row.get("coindcx_symbol")
                if not symbol:
                    summary["errors"].append(f"Skipped leaderboard row {row.get('id')} with no symbol")
                    continue
                try:
                    analyzed = self._analyze_row(row, resolved_date, quote_filter, min_change)
                    try:
                        affected = execute(self._upsert_sql(), self._upsert_params(analyzed))
                    except Exception as exc:
                        raise RuntimeError(f"upsert_missed_gainer: {exc}") from exc
                    summary["rows_analyzed"] += 1
                    summary["rows_upserted"] += 1 if affected >= 1 else 0
                    miss_type = analyzed.get("miss_type")
                    if miss_type in ("missed_completely", "captured_not_selected", "selected_no_trade_plan", "trade_plan_not_triggered"):
                        summary[miss_type] += 1
                    if analyzed.get("simulated_trade_created"):
                        summary["simulated_trade_created"] += 1
                except Exception as exc:
                    summary["errors"].append(f"{symbol} {exc}")
                    continue

            status = "warning" if summary["errors"] else "ok"
            write_health_log(SERVICE_NAME, status, f"Analyzed {summary['rows_analyzed']} daily gainers for {resolved_date}", summary)
            return summary
        except Exception as exc:
            summary["errors"].append(str(exc))
            try:
                write_health_log(SERVICE_NAME, "error", str(exc), summary)
            except Exception:
                pass
            raise

    def _run_lookup(self, step: str, callback):
        try:
            return callback()
        except Exception as exc:
            raise RuntimeError(f"{step}: {exc}") from exc

    def _load_leaderboard_rows(self, analysis_date: str, quote_filter: str, min_change: float, limit: int) -> list[dict]:
        return fetch_all(
            """
            SELECT *
            FROM daily_gainer_leaderboard
            WHERE leaderboard_date = %s
              AND quote_filter = %s
              AND change_24h_percent >= %s
            ORDER BY rank ASC
            LIMIT %s
            """,
            (analysis_date, quote_filter, min_change, limit),
        )

    def _analyze_row(self, leaderboard: dict, analysis_date: str, quote_filter: str, min_change: float) -> dict:
        scan_result = self._run_lookup("find_best_scan_result", lambda: self._best_scan_result(leaderboard, analysis_date))
        watchlist = self._run_lookup("find_candidate_watchlist", lambda: self._best_watchlist(leaderboard, scan_result, analysis_date))
        trade_plan = self._run_lookup("find_trade_plan", lambda: self._best_trade_plan(leaderboard, scan_result, watchlist, analysis_date))
        simulated_trade = self._run_lookup("find_simulated_trade", lambda: self._best_simulated_trade(leaderboard, scan_result, trade_plan, analysis_date))
        event_flags = self._run_lookup("find_trade_events", lambda: self._trade_event_flags(simulated_trade.get("id"))) if simulated_trade else {}

        actual_change = as_float(leaderboard.get("change_24h_percent"), 0.0) or 0.0
        matched = bool(scan_result)
        selected = bool(scan_result and scan_result.get("selected_for_watchlist")) or bool(watchlist)
        plan_created = bool(trade_plan)
        trade_created = bool(simulated_trade)
        miss_type, miss_reason, severity, action = self._classify(actual_change, scan_result, selected, trade_plan, simulated_trade)

        payload = {
            "source": SERVICE_NAME,
            "analysis": {
                "analysis_date": analysis_date,
                "quote_filter": quote_filter,
                "min_change": min_change,
                "leaderboard_rank": leaderboard.get("rank"),
                "actual_change_24h_percent": leaderboard.get("change_24h_percent"),
                "miss_type": miss_type,
                "miss_reason": miss_reason,
                "action_needed": action,
            },
            "links": {
                "leaderboard_id": leaderboard.get("id"),
                "best_scan_run_id": scan_result.get("scan_run_id") if scan_result else None,
                "best_scan_result_id": scan_result.get("id") if scan_result else None,
                "best_candidate_watchlist_id": watchlist.get("id") if watchlist else None,
                "best_trade_plan_id": trade_plan.get("id") if trade_plan else None,
                "best_simulated_trade_id": simulated_trade.get("id") if simulated_trade else None,
            },
            "flags": {
                "matched_in_scan": matched,
                "selected_for_watchlist": selected,
                "trade_plan_created": plan_created,
                "simulated_trade_created": trade_created,
                "entry_triggered": bool(event_flags.get("entry_triggered") or simulated_trade and simulated_trade.get("entry_triggered_at")),
                "tp1_hit": bool(event_flags.get("tp1_hit") or simulated_trade and simulated_trade.get("tp1_hit_at")),
                "tp2_hit": bool(event_flags.get("tp2_hit") or simulated_trade and simulated_trade.get("tp2_hit_at")),
                "sl_hit": bool(event_flags.get("sl_hit") or simulated_trade and simulated_trade.get("sl_hit_at")),
                "trailing_hit": bool(event_flags.get("trailing_hit") or simulated_trade and simulated_trade.get("trailing_stopped_at")),
                "expired": bool(event_flags.get("expired") or (simulated_trade and str(simulated_trade.get("status") or "").lower() == "expired")),
            },
        }
        flags = payload["flags"]
        return {
            "analysis_date": analysis_date,
            "leaderboard_id": leaderboard.get("id"),
            "leaderboard_rank": leaderboard.get("rank"),
            "spot_symbol_id": leaderboard.get("spot_symbol_id"),
            "coindcx_symbol": leaderboard.get("coindcx_symbol"),
            "api_pair": leaderboard.get("api_pair"),
            "base_asset": leaderboard.get("base_asset"),
            "quote_asset": leaderboard.get("quote_asset"),
            "actual_change_24h_percent": leaderboard.get("change_24h_percent"),
            "actual_last_price": leaderboard.get("last_price"),
            "actual_quote_volume_24h": leaderboard.get("quote_volume_24h"),
            "actual_spread_percent": leaderboard.get("spread_percent"),
            **flags,
            "best_scan_run_id": scan_result.get("scan_run_id") if scan_result else None,
            "best_scan_result_id": scan_result.get("id") if scan_result else None,
            "best_candidate_watchlist_id": watchlist.get("id") if watchlist else None,
            "best_trade_plan_id": trade_plan.get("id") if trade_plan else None,
            "best_simulated_trade_id": simulated_trade.get("id") if simulated_trade else None,
            "best_final_score": scan_result.get("final_score") if scan_result else None,
            "best_score_label": scan_result.get("score_label") if scan_result else None,
            "best_rank": scan_result.get("selection_rank") if scan_result else None,
            "selected_rank": scan_result.get("selection_rank") if scan_result and scan_result.get("selected_for_watchlist") else None,
            "miss_type": miss_type,
            "miss_reason": miss_reason,
            "miss_severity": severity,
            "action_needed": action,
            "notes": None,
            "prefilter_passed": scan_result.get("prefilter_passed") if scan_result else None,
            "score_passed": scan_result.get("score_passed") if scan_result else None,
            "fallback_selected": (scan_result.get("selection_type") == "fallback") if scan_result and scan_result.get("selection_type") else None,
            "rejection_reason": (scan_result.get("rejection_reason") or scan_result.get("selection_reason")) if scan_result else None,
            "setup_type": trade_plan.get("plan_type") if trade_plan else None,
            "entry_strategy": (trade_plan or watchlist or scan_result or {}).get("entry_strategy") or (scan_result or {}).get("suggested_entry_strategy"),
            "planned_entry_price": (simulated_trade or {}).get("planned_entry_price") or (trade_plan or {}).get("entry_price") or (scan_result or {}).get("suggested_entry_price"),
            "trigger_price": (simulated_trade or {}).get("trigger_price") or (trade_plan or {}).get("trigger_price") or (watchlist or {}).get("trigger_price") or (scan_result or {}).get("suggested_trigger_price"),
            "tp1_price": (simulated_trade or {}).get("tp1_price") or (trade_plan or {}).get("tp1_price") or (scan_result or {}).get("suggested_tp1_price"),
            "tp2_price": (simulated_trade or {}).get("tp2_price") or (trade_plan or {}).get("tp2_price") or (scan_result or {}).get("suggested_tp2_price"),
            "sl_price": (simulated_trade or {}).get("sl_price") or (trade_plan or {}).get("sl_price") or (scan_result or {}).get("suggested_sl_price"),
            "latest_trade_status": simulated_trade.get("status") if simulated_trade else None,
            "current_pnl_percent": simulated_trade.get("current_pnl_percent") if simulated_trade else None,
            "max_gain_percent": simulated_trade.get("max_gain_percent") if simulated_trade else None,
            "final_pnl_percent": simulated_trade.get("final_pnl_percent") if simulated_trade else None,
            "raw_payload": json.dumps(payload, separators=(",", ":"), default=json_default),
        }

    def _best_scan_result(self, leaderboard: dict, analysis_date: str) -> dict | None:
        return fetch_one(
            """
            SELECT sr.*
            FROM scan_results sr
            INNER JOIN scan_runs run ON run.id = sr.scan_run_id
            WHERE DATE(run.started_at) = %s
              AND (sr.coindcx_symbol = %s OR (%s IS NOT NULL AND sr.spot_symbol_id = %s))
            ORDER BY sr.selected_for_watchlist DESC, sr.final_score DESC, sr.id DESC
            LIMIT 1
            """,
            (analysis_date, leaderboard.get("coindcx_symbol"), leaderboard.get("spot_symbol_id"), leaderboard.get("spot_symbol_id")),
        )

    def _best_watchlist(self, leaderboard: dict, scan_result: dict | None, analysis_date: str) -> dict | None:
        if scan_result and scan_result.get("candidate_watchlist_id"):
            row = fetch_one("SELECT * FROM candidate_watchlists WHERE id = %s LIMIT 1", (scan_result.get("candidate_watchlist_id"),))
            if row:
                return row
        return fetch_one(
            """
            SELECT * FROM candidate_watchlists
            WHERE coindcx_symbol = %s AND DATE(created_at) = %s
            ORDER BY score DESC, id DESC
            LIMIT 1
            """,
            (leaderboard.get("coindcx_symbol"), analysis_date),
        )

    def _best_trade_plan(self, leaderboard: dict, scan_result: dict | None, watchlist: dict | None, analysis_date: str) -> dict | None:
        clauses = []
        params = []
        if scan_result:
            clauses.append("scan_result_id = %s")
            params.append(scan_result.get("id"))
        if watchlist:
            clauses.append("candidate_watchlist_id = %s")
            params.append(watchlist.get("id"))
        clauses.append("(coindcx_symbol = %s AND DATE(created_at) = %s)")
        params.extend([leaderboard.get("coindcx_symbol"), analysis_date])
        return fetch_one(
            f"""
            SELECT * FROM trade_plans
            WHERE {' OR '.join(clauses)}
            ORDER BY CASE status WHEN 'triggered' THEN 1 WHEN 'converted_to_trade' THEN 2 WHEN 'watching' THEN 3 WHEN 'pending' THEN 4 WHEN 'expired' THEN 5 ELSE 6 END ASC, id DESC
            LIMIT 1
            """,
            tuple(params),
        )

    def _best_simulated_trade(self, leaderboard: dict, scan_result: dict | None, trade_plan: dict | None, analysis_date: str) -> dict | None:
        clauses = []
        params = []
        if trade_plan:
            clauses.append("trade_plan_id = %s")
            params.append(trade_plan.get("id"))
        if scan_result:
            clauses.append("scan_result_id = %s")
            params.append(scan_result.get("id"))
        clauses.append("(coindcx_symbol = %s AND DATE(created_at) = %s)")
        params.extend([leaderboard.get("coindcx_symbol"), analysis_date])
        return fetch_one(f"SELECT * FROM simulated_trades WHERE {' OR '.join(clauses)} ORDER BY id DESC LIMIT 1", tuple(params))

    def _trade_event_flags(self, simulated_trade_id: int | None) -> dict:
        if not simulated_trade_id:
            return {}
        rows = fetch_all("SELECT event_type FROM trade_events WHERE simulated_trade_id = %s", (simulated_trade_id,))
        types = {str(row.get("event_type") or "").upper() for row in rows}
        return {
            "entry_triggered": "ENTRY_TRIGGERED" in types,
            "tp1_hit": "TP1_HIT" in types,
            "tp2_hit": "TP2_HIT" in types,
            "sl_hit": "SL_HIT" in types,
            "trailing_hit": "TRAILING_STOP_HIT" in types,
            "expired": "EXPIRED" in types,
        }

    def _classify(self, actual_change: float, scan_result: dict | None, selected: bool, trade_plan: dict | None, simulated_trade: dict | None) -> tuple[str, str, str, str]:
        if not scan_result:
            return "missed_completely", "not_in_scan_results", "high", "review_prefilter_or_scan_timing"
        if not selected:
            return "captured_not_selected", self._captured_not_selected_reason(scan_result), self._severity(actual_change), "review_score_weights_thresholds"
        if not trade_plan:
            return "selected_no_trade_plan", "selected_but_no_trade_plan", "medium", "review_trade_plan_generation"
        if not simulated_trade:
            reason = "plan_expired_not_triggered" if str(trade_plan.get("status") or "").lower() == "expired" else "entry_not_triggered"
            return "trade_plan_not_triggered", reason, "medium", "review_entry_strategy_trigger_price"
        return "captured_trade_created", "not_missed_trade_created", "none", "none"

    def _captured_not_selected_reason(self, scan_result: dict) -> str:
        reason_text = " ".join(str(scan_result.get(key) or "") for key in ("rejection_reason", "selection_reason", "prefilter_reason", "raw_payload")).lower()
        if any(token in reason_text for token in ("filter", "prefilter", "rejected")):
            return "failed_filter"
        if scan_result.get("score_passed") in (0, False) or (as_float(scan_result.get("final_score")) is not None and as_float(scan_result.get("final_score"), 0) < 50):
            return "score_below_threshold"
        if scan_result.get("selection_rank") and int(scan_result.get("selection_rank") or 0) > 0 and scan_result.get("selection_type") != "fallback":
            return "outside_top_n_fallback"
        if str(scan_result.get("score_label") or "").lower() in ("weak", "low", "avoid", "poor"):
            return "weak_score_label"
        return "unknown_rejection"

    def _severity(self, actual_change: float) -> str:
        if actual_change >= 25:
            return "critical"
        if actual_change >= 20:
            return "high"
        if actual_change >= 10:
            return "medium"
        return "low"

    def _upsert_params(self, row: dict) -> tuple:
        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        columns = self._upsert_columns()
        return tuple(row.get(column) for column in columns) + (now, now, now)

    def _upsert_columns(self) -> list[str]:
        return [
            "analysis_date","leaderboard_id","leaderboard_rank","spot_symbol_id","coindcx_symbol","api_pair","base_asset","quote_asset","actual_change_24h_percent","actual_last_price","actual_quote_volume_24h","actual_spread_percent",
            "matched_in_scan","selected_for_watchlist","trade_plan_created","simulated_trade_created","entry_triggered","tp1_hit","tp2_hit","sl_hit","trailing_hit","expired",
            "best_scan_run_id","best_scan_result_id","best_candidate_watchlist_id","best_trade_plan_id","best_simulated_trade_id","best_final_score","best_score_label","best_rank","selected_rank",
            "miss_type","miss_reason","miss_severity","action_needed","notes","prefilter_passed","score_passed","fallback_selected","rejection_reason","setup_type","entry_strategy","planned_entry_price","trigger_price","tp1_price","tp2_price","sl_price","latest_trade_status","current_pnl_percent","max_gain_percent","final_pnl_percent","raw_payload",
        ]

    def _upsert_sql(self) -> str:
        columns = self._upsert_columns()
        insert_columns = columns + ["analyzed_at", "created_at", "updated_at"]
        placeholders = ", ".join(["%s"] * len(insert_columns))
        updates = ", ".join([f"{column} = VALUES({column})" for column in columns if column not in ("analysis_date", "coindcx_symbol")])
        updates += ", analyzed_at = VALUES(analyzed_at), updated_at = VALUES(updated_at)"
        return f"""
            INSERT INTO missed_gainers ({', '.join(insert_columns)})
            VALUES ({placeholders})
            ON DUPLICATE KEY UPDATE {updates}
        """
