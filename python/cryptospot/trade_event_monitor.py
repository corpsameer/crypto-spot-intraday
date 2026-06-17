import json
from datetime import datetime
from typing import Any

from cryptospot.db import fetch_all, fetch_one, get_connection
from cryptospot.health import write_health_log
from cryptospot.logger import get_logger
from cryptospot.scan_runner import safe_float

SERVICE_NAME = "trade_event_monitor"
OPEN_STATUSES = ("active", "tp1_hit", "tp2_hit", "trailing_active")

logger = get_logger(__name__)


class TradeEventMonitor:
    def run_once(self, limit: int = None) -> dict:
        summary = {
            "trades_loaded": 0,
            "trades_checked": 0,
            "tp1_events_created": 0,
            "tp2_events_created": 0,
            "sl_events_created": 0,
            "trades_closed_sl": 0,
            "trades_updated": 0,
            "skipped": 0,
            "errors": [],
        }

        try:
            trades = self._load_open_trades(limit)
            summary["trades_loaded"] = len(trades)

            now = datetime.now()
            for trade in trades:
                try:
                    self._process_trade(trade, now, summary)
                except Exception as exc:
                    logger.exception("Failed to process simulated_trade id=%s", trade.get("id"))
                    summary["errors"].append({"trade_id": trade.get("id"), "error": str(exc)})
                    summary["skipped"] += 1

            status = "warning" if summary["errors"] else "ok"
            message = (
                f"Checked {summary['trades_checked']} trades, "
                f"TP1 {summary['tp1_events_created']}, "
                f"TP2 {summary['tp2_events_created']}, "
                f"SL {summary['sl_events_created']}"
            )
            if summary["errors"]:
                message = f"{message}, errors {len(summary['errors'])}"
            self._write_health(status, message, summary)
            return summary
        except Exception as exc:
            logger.exception("Trade event monitor failed")
            summary["errors"].append({"fatal": str(exc)})
            self._write_health("error", str(exc), summary)
            raise

    def _load_open_trades(self, limit: int = None) -> list[dict]:
        query = """
            SELECT
                id,
                scan_run_id,
                scan_result_id,
                candidate_watchlist_id,
                trade_plan_id,
                spot_symbol_id,
                scanner_metric_id,
                coindcx_symbol,
                api_pair,
                base_asset,
                quote_asset,
                side,
                status,
                source,
                planned_entry_price,
                trigger_price,
                entry_price,
                entry_triggered_at,
                tp1_price,
                tp2_price,
                sl_price,
                trailing_start_price,
                current_trailing_sl_price,
                tp1_percent,
                tp2_percent,
                sl_percent,
                trailing_active,
                latest_price,
                highest_price,
                lowest_price,
                max_gain_percent,
                max_drawdown_percent,
                current_pnl_percent,
                final_pnl_percent,
                tp1_hit_at,
                tp2_hit_at,
                sl_hit_at,
                trailing_started_at,
                trailing_stopped_at,
                closed_at,
                expires_at,
                close_price,
                close_reason,
                score,
                score_label,
                entry_strategy,
                raw_payload
            FROM simulated_trades
            WHERE status IN ('active', 'tp1_hit', 'tp2_hit', 'trailing_active')
            AND closed_at IS NULL
            ORDER BY entry_triggered_at ASC, updated_at ASC
        """
        params = None
        if limit is not None and int(limit) > 0:
            query += " LIMIT %s"
            params = (int(limit),)
        return fetch_all(query, params)

    def _process_trade(self, trade: dict, now: datetime, summary: dict) -> None:
        trade_id = trade["id"]
        side = (trade.get("side") or "").lower()
        if side != "long":
            summary["errors"].append({"trade_id": trade_id, "warning": f"unsupported_side:{side or 'missing'}"})
            summary["skipped"] += 1
            return

        entry_price = safe_float(trade.get("entry_price"))
        latest_price = safe_float(trade.get("latest_price"))
        if entry_price is None or entry_price <= 0:
            summary["errors"].append({"trade_id": trade_id, "error": "missing_or_invalid_entry_price"})
            summary["skipped"] += 1
            return
        if latest_price is None or latest_price <= 0:
            summary["skipped"] += 1
            return

        summary["trades_checked"] += 1
        sl_price = safe_float(trade.get("sl_price"))
        if sl_price is not None and sl_price > 0 and latest_price <= sl_price and trade.get("sl_hit_at") is None and trade.get("closed_at") is None:
            self._handle_event(trade, "SL_HIT", sl_price, latest_price, entry_price, now, summary)
            return

        tp1_price = safe_float(trade.get("tp1_price"))
        if tp1_price is not None and tp1_price > 0 and latest_price >= tp1_price and trade.get("tp1_hit_at") is None:
            self._handle_event(trade, "TP1_HIT", tp1_price, latest_price, entry_price, now, summary)
            trade["status"] = "tp1_hit" if trade.get("status") == "active" else trade.get("status")
            trade["tp1_hit_at"] = now

        tp2_price = safe_float(trade.get("tp2_price"))
        if tp2_price is not None and tp2_price > 0 and latest_price >= tp2_price and trade.get("tp2_hit_at") is None:
            self._handle_event(trade, "TP2_HIT", tp2_price, latest_price, entry_price, now, summary)

    def _handle_event(self, trade: dict, event_type: str, trigger_price: float, event_price: float, entry_price: float, now: datetime, summary: dict) -> None:
        existing = fetch_one(
            "SELECT id FROM trade_events WHERE simulated_trade_id = %s AND event_type = %s LIMIT 1",
            (trade["id"], event_type),
        )
        pnl = ((event_price - entry_price) / entry_price) * 100
        if event_type == "TP1_HIT":
            new_status, timestamp_field, message = "tp1_hit", "tp1_hit_at", "TP1 hit for simulated trade."
            counter = "tp1_events_created"
        elif event_type == "TP2_HIT":
            new_status, timestamp_field, message = "tp2_hit", "tp2_hit_at", "TP2 hit for simulated trade."
            counter = "tp2_events_created"
        else:
            new_status, timestamp_field, message = "closed_sl", "sl_hit_at", "Stop loss hit. Simulated trade closed."
            counter = "sl_events_created"

        previous_status = trade.get("status")
        payload = self._merged_trade_payload(trade.get("raw_payload"), event_type, now)

        connection = get_connection()
        cursor = None
        try:
            cursor = connection.cursor()
            if not existing:
                cursor.execute(
                    """
                    INSERT INTO trade_events
                        (simulated_trade_id, trade_plan_id, scan_run_id, scan_result_id, candidate_watchlist_id, spot_symbol_id,
                         coindcx_symbol, event_type, event_time, event_price, previous_price, trigger_price,
                         actual_price_move_percent, pnl_percent, max_gain_percent, max_drawdown_percent,
                         previous_status, new_status, message, raw_payload, created_at, updated_at)
                    VALUES
                        (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, NULL, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                    """,
                    (
                        trade.get("id"), trade.get("trade_plan_id"), trade.get("scan_run_id"), trade.get("scan_result_id"),
                        trade.get("candidate_watchlist_id"), trade.get("spot_symbol_id"), trade.get("coindcx_symbol"),
                        event_type, now, event_price, trigger_price, pnl, pnl, safe_float(trade.get("max_gain_percent")),
                        safe_float(trade.get("max_drawdown_percent")), previous_status, new_status, message,
                        json.dumps(self._event_payload(event_type, event_price, entry_price, trigger_price, pnl, trade), separators=(",", ":"), default=str),
                        now, now,
                    ),
                )
                summary[counter] += 1

            if event_type == "SL_HIT":
                cursor.execute(
                    """
                    UPDATE simulated_trades
                    SET sl_hit_at = COALESCE(sl_hit_at, %s), status = 'closed_sl', closed_at = COALESCE(closed_at, %s),
                        close_price = %s, close_reason = 'sl', final_pnl_percent = %s, current_pnl_percent = %s,
                        raw_payload = %s, updated_at = %s
                    WHERE id = %s AND closed_at IS NULL
                    """,
                    (now, now, event_price, pnl, pnl, json.dumps(payload, separators=(",", ":"), default=str), now, trade["id"]),
                )
                if cursor.rowcount > 0:
                    summary["trades_closed_sl"] += 1
                    summary["trades_updated"] += 1
            else:
                status_sql = "status = IF(status = 'active', 'tp1_hit', status)" if event_type == "TP1_HIT" else "status = 'tp2_hit'"
                cursor.execute(
                    f"""
                    UPDATE simulated_trades
                    SET {timestamp_field} = COALESCE({timestamp_field}, %s), {status_sql}, current_pnl_percent = %s,
                        raw_payload = %s, updated_at = %s
                    WHERE id = %s AND closed_at IS NULL
                    """,
                    (now, pnl, json.dumps(payload, separators=(",", ":"), default=str), now, trade["id"]),
                )
                if cursor.rowcount > 0:
                    summary["trades_updated"] += 1
            connection.commit()
        except Exception:
            connection.rollback()
            raise
        finally:
            if cursor:
                cursor.close()
            connection.close()

    def _event_payload(self, event_type: str, event_price: float, entry_price: float, trigger_price: float, pnl: float, trade: dict) -> dict:
        return {
            "source": "trade_event_monitor",
            "simulation_only": True,
            "condition": {"latest_price": event_price, "entry_price": entry_price, "trigger_price": trigger_price, "event_type": event_type},
            "pnl": {
                "actual_price_move_percent": pnl,
                "pnl_percent": pnl,
                "max_gain_percent": safe_float(trade.get("max_gain_percent")),
                "max_drawdown_percent": safe_float(trade.get("max_drawdown_percent")),
            },
        }

    def _merged_trade_payload(self, raw_payload: Any, event_type: str, now: datetime) -> dict:
        payload = self._decode_payload(raw_payload)
        monitor = payload.get("trade_event_monitor") if isinstance(payload.get("trade_event_monitor"), dict) else {}
        monitor["last_checked_at"] = now.strftime("%Y-%m-%d %H:%M:%S")
        if event_type == "TP1_HIT":
            monitor.update({"tp1_hit": True, "tp1_hit_at": monitor["last_checked_at"]})
        elif event_type == "TP2_HIT":
            monitor.update({"tp2_hit": True, "tp2_hit_at": monitor["last_checked_at"]})
        elif event_type == "SL_HIT":
            monitor.update({"sl_hit": True, "sl_hit_at": monitor["last_checked_at"], "closed_by": "sl"})
        payload["trade_event_monitor"] = monitor
        return payload

    @staticmethod
    def _decode_payload(raw_payload: Any) -> dict:
        if isinstance(raw_payload, dict):
            return raw_payload
        if raw_payload:
            try:
                decoded = json.loads(raw_payload)
                return decoded if isinstance(decoded, dict) else {}
            except (TypeError, ValueError):
                return {}
        return {}

    @staticmethod
    def _write_health(status, message, meta):
        try:
            write_health_log(SERVICE_NAME, status, message, meta)
        except Exception:
            logger.exception("Failed to write trade event monitor health log")
