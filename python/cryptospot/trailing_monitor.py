import json
from datetime import datetime
from typing import Any

from cryptospot.db import fetch_all, fetch_one, get_connection
from cryptospot.health import write_health_log
from cryptospot.logger import get_logger
from cryptospot.scan_runner import safe_float
from cryptospot.settings import get_setting

SERVICE_NAME = "trailing_monitor"
DEFAULT_TRAILING_LEVELS = [
    {"gain_percent": 10, "lock_percent": 6},
    {"gain_percent": 12, "lock_percent": 8},
    {"gain_percent": 15, "lock_percent": 11},
    {"gain_percent": 20, "lock_percent": 15},
    {"gain_percent": 25, "lock_percent": 19},
    {"gain_percent": 30, "lock_percent": 24},
]

logger = get_logger(__name__)


class TrailingMonitor:
    def run_once(self, limit: int = None) -> dict:
        summary = {
            "trades_loaded": 0,
            "trades_checked": 0,
            "trailing_started": 0,
            "trailing_updated": 0,
            "trailing_stop_hits": 0,
            "trades_closed": 0,
            "trades_updated": 0,
            "skipped": 0,
            "errors": [],
        }

        try:
            if not self._trailing_enabled():
                summary["skipped"] += 1
                self._write_health("ok", "Trailing monitor disabled by trailing.enabled", summary)
                return summary

            levels = self._trailing_levels()
            min_update_step = self._min_update_step_percent()
            close_on_trailing_stop = self._close_on_trailing_stop()
            trades = self._load_open_trades(limit)
            summary["trades_loaded"] = len(trades)

            now = datetime.now()
            for trade in trades:
                try:
                    self._process_trade(trade, now, levels, min_update_step, close_on_trailing_stop, summary)
                except Exception as exc:
                    logger.exception("Failed to process trailing for simulated_trade id=%s", trade.get("id"))
                    summary["errors"].append({"trade_id": trade.get("id"), "error": str(exc)})
                    summary["skipped"] += 1

            status = "warning" if summary["errors"] else "ok"
            message = (
                f"Checked {summary['trades_checked']} trades, started {summary['trailing_started']}, "
                f"updated {summary['trailing_updated']}, closed {summary['trades_closed']}"
            )
            if summary["errors"]:
                message = f"{message}, errors {len(summary['errors'])}"
            self._write_health(status, message, summary)
            return summary
        except Exception as exc:
            logger.exception("Trailing monitor failed")
            summary["errors"].append({"fatal": str(exc)})
            self._write_health("error", str(exc), summary)
            raise

    def _load_open_trades(self, limit: int = None) -> list[dict]:
        query = """
            SELECT
                id, scan_run_id, scan_result_id, candidate_watchlist_id, trade_plan_id, spot_symbol_id,
                scanner_metric_id, coindcx_symbol, api_pair, base_asset, quote_asset, side, status, source,
                planned_entry_price, trigger_price, entry_price, entry_triggered_at, tp1_price, tp2_price,
                sl_price, trailing_start_price, current_trailing_sl_price, tp1_percent, tp2_percent, sl_percent,
                trailing_active, latest_price, highest_price, lowest_price, max_gain_percent, max_drawdown_percent,
                current_pnl_percent, final_pnl_percent, tp1_hit_at, tp2_hit_at, sl_hit_at, trailing_started_at,
                trailing_stopped_at, closed_at, expires_at, close_price, close_reason, score, score_label,
                entry_strategy, raw_payload
            FROM simulated_trades
            WHERE status IN ('active', 'tp1_hit', 'tp2_hit', 'trailing_active')
            AND closed_at IS NULL
            AND sl_hit_at IS NULL
            ORDER BY entry_triggered_at ASC, updated_at ASC
        """
        params = None
        if limit is not None and int(limit) > 0:
            query += " LIMIT %s"
            params = (int(limit),)
        return fetch_all(query, params)

    def _process_trade(self, trade: dict, now: datetime, levels: list[dict], min_update_step: float, close_on_trailing_stop: bool, summary: dict) -> None:
        trade_id = trade["id"]
        if (trade.get("side") or "").lower() != "long":
            summary["skipped"] += 1
            return

        entry_price = safe_float(trade.get("entry_price"))
        latest_price = safe_float(trade.get("latest_price"))
        highest_price = safe_float(trade.get("highest_price")) or latest_price
        if entry_price is None or entry_price <= 0 or latest_price is None or latest_price <= 0:
            summary["skipped"] += 1
            return

        tp2_hit = trade.get("tp2_hit_at") is not None or trade.get("status") in ("tp2_hit", "trailing_active")
        if not tp2_hit:
            summary["skipped"] += 1
            return

        max_gain_percent = safe_float(trade.get("max_gain_percent"))
        if max_gain_percent is None and highest_price is not None:
            max_gain_percent = ((highest_price - entry_price) / entry_price) * 100
        if max_gain_percent is None:
            summary["skipped"] += 1
            return

        summary["trades_checked"] += 1
        locked_gain_percent, trailing_sl_price = self._calculate_trailing_sl(entry_price, max_gain_percent, levels)
        existing_sl = safe_float(trade.get("current_trailing_sl_price"))
        trailing_active = self._truthy(trade.get("trailing_active")) or trade.get("status") == "trailing_active"
        started_this_cycle = False

        if not trailing_active:
            self._start_trailing(trade, now, trailing_sl_price, locked_gain_percent, max_gain_percent, summary)
            trade["trailing_active"] = 1
            trade["status"] = "trailing_active"
            trade["trailing_started_at"] = trade.get("trailing_started_at") or now
            trade["current_trailing_sl_price"] = trailing_sl_price
            existing_sl = trailing_sl_price
            trailing_active = True
            started_this_cycle = True

        if trailing_active:
            if existing_sl is None or trailing_sl_price > existing_sl:
                event_improvement = self._locked_gain_improvement(existing_sl, entry_price, locked_gain_percent)
                create_event = (not started_this_cycle) and event_improvement >= min_update_step
                self._update_trailing_sl(trade, now, trailing_sl_price, locked_gain_percent, max_gain_percent, create_event, summary)
                trade["current_trailing_sl_price"] = trailing_sl_price
                existing_sl = trailing_sl_price

            if close_on_trailing_stop and existing_sl is not None and existing_sl > 0 and latest_price <= existing_sl:
                self._close_trailing(trade, now, existing_sl, entry_price, latest_price, locked_gain_percent, max_gain_percent, summary)

    def _start_trailing(self, trade, now, trailing_sl_price, locked_gain_percent, max_gain_percent, summary):
        previous_status = trade.get("status")
        event_exists = self._event_exists(trade["id"], "TRAILING_STARTED")
        payload = self._merged_trade_payload(trade.get("raw_payload"), now, True, trailing_sl_price, locked_gain_percent, trade, max_gain_percent, False, None)
        connection = get_connection(); cursor = None
        try:
            cursor = connection.cursor()
            if not event_exists:
                self._insert_event(cursor, trade, "TRAILING_STARTED", now, safe_float(trade.get("latest_price")), safe_float(trade.get("trailing_start_price")) or safe_float(trade.get("tp2_price")), previous_status, "trailing_active", "Trailing activated after TP2.", locked_gain_percent, max_gain_percent)
                summary["trailing_started"] += 1
            cursor.execute(
                """
                UPDATE simulated_trades
                SET trailing_active = 1, trailing_started_at = COALESCE(trailing_started_at, %s), status = 'trailing_active',
                    current_trailing_sl_price = %s, raw_payload = %s, updated_at = %s
                WHERE id = %s AND closed_at IS NULL
                """,
                (now, trailing_sl_price, json.dumps(payload, separators=(",", ":"), default=str), now, trade["id"]),
            )
            if cursor.rowcount > 0:
                summary["trades_updated"] += 1
            connection.commit()
        except Exception:
            connection.rollback(); raise
        finally:
            if cursor: cursor.close()
            connection.close()

    def _update_trailing_sl(self, trade, now, trailing_sl_price, locked_gain_percent, max_gain_percent, create_event, summary):
        payload = self._merged_trade_payload(trade.get("raw_payload"), now, True, trailing_sl_price, locked_gain_percent, trade, max_gain_percent, False, None)
        connection = get_connection(); cursor = None
        try:
            cursor = connection.cursor()
            if create_event and not self._trailing_update_event_exists(trade["id"], trailing_sl_price):
                self._insert_event(cursor, trade, "TRAILING_UPDATED", now, safe_float(trade.get("latest_price")), trailing_sl_price, trade.get("status"), "trailing_active", "Trailing stop updated.", locked_gain_percent, max_gain_percent)
                summary["trailing_updated"] += 1
            cursor.execute(
                """
                UPDATE simulated_trades
                SET current_trailing_sl_price = %s, raw_payload = %s, updated_at = %s
                WHERE id = %s AND closed_at IS NULL AND (current_trailing_sl_price IS NULL OR current_trailing_sl_price < %s)
                """,
                (trailing_sl_price, json.dumps(payload, separators=(",", ":"), default=str), now, trade["id"], trailing_sl_price),
            )
            if cursor.rowcount > 0:
                summary["trades_updated"] += 1
            connection.commit()
        except Exception:
            connection.rollback(); raise
        finally:
            if cursor: cursor.close()
            connection.close()

    def _close_trailing(self, trade, now, trailing_sl_price, entry_price, latest_price, locked_gain_percent, max_gain_percent, summary):
        final_pnl = ((latest_price - entry_price) / entry_price) * 100
        event_exists = self._event_exists(trade["id"], "TRAILING_STOP_HIT")
        payload = self._merged_trade_payload(trade.get("raw_payload"), now, True, trailing_sl_price, locked_gain_percent, trade, max_gain_percent, True, now)
        connection = get_connection(); cursor = None
        try:
            cursor = connection.cursor()
            if not event_exists:
                self._insert_event(cursor, trade, "TRAILING_STOP_HIT", now, latest_price, trailing_sl_price, "trailing_active", "closed_trailing", "Trailing stop hit. Simulated trade closed.", locked_gain_percent, max_gain_percent, final_pnl)
                summary["trailing_stop_hits"] += 1
            cursor.execute(
                """
                UPDATE simulated_trades
                SET status = 'closed_trailing', trailing_stopped_at = COALESCE(trailing_stopped_at, %s), closed_at = COALESCE(closed_at, %s),
                    close_price = %s, close_reason = 'trailing_stop', final_pnl_percent = %s, current_pnl_percent = %s,
                    raw_payload = %s, updated_at = %s
                WHERE id = %s AND closed_at IS NULL
                """,
                (now, now, latest_price, final_pnl, final_pnl, json.dumps(payload, separators=(",", ":"), default=str), now, trade["id"]),
            )
            if cursor.rowcount > 0:
                summary["trades_closed"] += 1; summary["trades_updated"] += 1
            connection.commit()
        except Exception:
            connection.rollback(); raise
        finally:
            if cursor: cursor.close()
            connection.close()

    def _insert_event(self, cursor, trade, event_type, now, event_price, trigger_price, previous_status, new_status, message, locked_gain_percent, max_gain_percent, pnl_percent=None):
        pnl = pnl_percent if pnl_percent is not None else safe_float(trade.get("current_pnl_percent"))
        cursor.execute(
            """
            INSERT INTO trade_events
                (simulated_trade_id, trade_plan_id, scan_run_id, scan_result_id, candidate_watchlist_id, spot_symbol_id,
                 coindcx_symbol, event_type, event_time, event_price, previous_price, trigger_price,
                 actual_price_move_percent, pnl_percent, max_gain_percent, max_drawdown_percent,
                 previous_status, new_status, message, raw_payload, created_at, updated_at)
            VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, NULL, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            """,
            (trade.get("id"), trade.get("trade_plan_id"), trade.get("scan_run_id"), trade.get("scan_result_id"), trade.get("candidate_watchlist_id"), trade.get("spot_symbol_id"), trade.get("coindcx_symbol"), event_type, now, event_price, trigger_price, pnl, pnl, max_gain_percent, safe_float(trade.get("max_drawdown_percent")), previous_status, new_status, message, json.dumps(self._event_payload(trade, event_type, event_price, trigger_price, locked_gain_percent, max_gain_percent), separators=(",", ":"), default=str), now, now),
        )

    def _calculate_trailing_sl(self, entry_price: float, max_gain_percent: float, levels: list[dict]) -> tuple[float, float]:
        chosen = None
        for level in sorted(levels, key=lambda item: safe_float(item.get("gain_percent")) or 0):
            gain = safe_float(level.get("gain_percent")); lock = safe_float(level.get("lock_percent"))
            if gain is not None and lock is not None and max_gain_percent >= gain:
                chosen = lock
        locked_gain_percent = chosen if chosen is not None else 6.0
        return locked_gain_percent, entry_price * (1 + locked_gain_percent / 100)

    def _locked_gain_improvement(self, existing_sl: float | None, entry_price: float, new_locked_gain: float) -> float:
        if existing_sl is None or existing_sl <= 0:
            return new_locked_gain
        existing_locked_gain = ((existing_sl - entry_price) / entry_price) * 100
        return new_locked_gain - existing_locked_gain

    def _event_payload(self, trade, event_type, event_price, trigger_price, locked_gain_percent, max_gain_percent):
        return {"source": "trailing_monitor", "simulation_only": True, "trailing": {"latest_price": safe_float(trade.get("latest_price")), "entry_price": safe_float(trade.get("entry_price")), "highest_price": safe_float(trade.get("highest_price")), "max_gain_percent": max_gain_percent, "locked_gain_percent": locked_gain_percent, "current_trailing_sl_price": trigger_price, "event_type": event_type}}

    def _merged_trade_payload(self, raw_payload: Any, now, trailing_active, trailing_sl_price, locked_gain_percent, trade, max_gain_percent, stop_hit, stopped_at):
        payload = self._decode_payload(raw_payload)
        payload["trailing_monitor"] = {"last_checked_at": now.strftime("%Y-%m-%d %H:%M:%S"), "trailing_active": trailing_active, "trailing_started_at": self._format_dt(trade.get("trailing_started_at")) or now.strftime("%Y-%m-%d %H:%M:%S"), "current_trailing_sl_price": trailing_sl_price, "locked_gain_percent": locked_gain_percent, "latest_price": safe_float(trade.get("latest_price")), "highest_price": safe_float(trade.get("highest_price")), "max_gain_percent": max_gain_percent, "trailing_stop_hit": stop_hit, "trailing_stopped_at": self._format_dt(stopped_at)}
        return payload

    def _event_exists(self, trade_id, event_type):
        return fetch_one("SELECT id FROM trade_events WHERE simulated_trade_id = %s AND event_type = %s LIMIT 1", (trade_id, event_type)) is not None

    def _trailing_update_event_exists(self, trade_id, trigger_price):
        row = fetch_one("SELECT id FROM trade_events WHERE simulated_trade_id = %s AND event_type = 'TRAILING_UPDATED' AND ABS(trigger_price - %s) < 0.00000001 LIMIT 1", (trade_id, trigger_price))
        return row is not None

    def _trailing_enabled(self):
        return bool(get_setting("trailing.enabled", True))

    def _trailing_levels(self):
        try:
            levels = get_setting("trailing.levels", DEFAULT_TRAILING_LEVELS)
            return levels if isinstance(levels, list) and levels else DEFAULT_TRAILING_LEVELS
        except Exception:
            return DEFAULT_TRAILING_LEVELS

    def _min_update_step_percent(self):
        try:
            return float(get_setting("trailing.min_update_step_percent", 0.25) or 0.25)
        except Exception:
            return 0.25

    def _close_on_trailing_stop(self):
        return bool(get_setting("trailing.close_on_trailing_stop", True))

    @staticmethod
    def _truthy(value):
        return str(value).lower() in ("1", "true", "yes")

    @staticmethod
    def _decode_payload(raw_payload: Any) -> dict:
        if isinstance(raw_payload, dict): return raw_payload
        if raw_payload:
            try:
                decoded = json.loads(raw_payload); return decoded if isinstance(decoded, dict) else {}
            except (TypeError, ValueError):
                return {}
        return {}

    @staticmethod
    def _format_dt(value):
        if value is None: return None
        if hasattr(value, "strftime"): return value.strftime("%Y-%m-%d %H:%M:%S")
        return str(value)

    @staticmethod
    def _write_health(status, message, meta):
        try:
            write_health_log(SERVICE_NAME, status, message, meta)
        except Exception:
            logger.exception("Failed to write trailing monitor health log")
