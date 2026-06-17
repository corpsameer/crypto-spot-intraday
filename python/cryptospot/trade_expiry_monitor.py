import json
from datetime import datetime
from typing import Any

from cryptospot.db import fetch_all, fetch_one, get_connection
from cryptospot.health import write_health_log
from cryptospot.logger import get_logger
from cryptospot.scan_runner import safe_float

SERVICE_NAME = "trade_expiry_monitor"
EXPIRED_MESSAGE = "Simulated trade expired and closed at latest available price."
OPEN_STATUSES = ("active", "tp1_hit", "tp2_hit", "trailing_active")

logger = get_logger(__name__)


class TradeExpiryMonitor:
    def run_once(self, limit: int = None) -> dict:
        summary = {
            "trades_loaded": 0,
            "trades_checked": 0,
            "events_created": 0,
            "trades_expired": 0,
            "trades_updated": 0,
            "skipped": 0,
            "errors": [],
        }

        try:
            trades = self._load_expired_open_trades(limit)
            summary["trades_loaded"] = len(trades)
            now = datetime.now()

            for trade in trades:
                try:
                    self._process_trade(trade, now, summary)
                except Exception as exc:
                    logger.exception("Failed to expire simulated_trade id=%s", trade.get("id"))
                    summary["errors"].append({"trade_id": trade.get("id"), "error": str(exc)})
                    summary["skipped"] += 1

            status = "warning" if summary["errors"] else "ok"
            message = f"Checked {summary['trades_checked']} expired trades, closed {summary['trades_expired']}"
            if summary["errors"]:
                message = f"{message}, errors {len(summary['errors'])}"
            self._write_health(status, message, summary)
            return summary
        except Exception as exc:
            logger.exception("Trade expiry monitor failed")
            summary["errors"].append({"fatal": str(exc)})
            self._write_health("error", str(exc), summary)
            raise

    def _load_expired_open_trades(self, limit: int = None) -> list[dict]:
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
            AND expires_at IS NOT NULL
            AND expires_at <= NOW()
            ORDER BY expires_at ASC, updated_at ASC
        """
        params = None
        if limit is not None and int(limit) > 0:
            query += " LIMIT %s"
            params = (int(limit),)
        return fetch_all(query, params)

    def _process_trade(self, trade: dict, now: datetime, summary: dict) -> None:
        summary["trades_checked"] += 1
        trade_id = trade["id"]

        if (trade.get("side") or "long").lower() != "long":
            self._skip(summary, trade_id, "unsupported_side")
            return

        entry_price = safe_float(trade.get("entry_price"))
        if entry_price is None or entry_price <= 0:
            self._skip(summary, trade_id, "missing_entry_price")
            return

        previous_latest_price = safe_float(trade.get("latest_price"))
        close_price = previous_latest_price if previous_latest_price is not None and previous_latest_price > 0 else entry_price
        if close_price is None or close_price <= 0:
            self._skip(summary, trade_id, "missing_close_price")
            return

        final_pnl = ((close_price - entry_price) / entry_price) * 100
        previous_status = trade.get("status")
        event_exists = self._expired_event_exists(trade_id)
        trade_payload = self._merged_trade_payload(trade.get("raw_payload"), now, trade, close_price, final_pnl)

        connection = get_connection()
        cursor = None
        try:
            cursor = connection.cursor()
            if not event_exists:
                self._insert_expired_event(cursor, trade, now, close_price, previous_latest_price, final_pnl, previous_status)
                summary["events_created"] += 1

            cursor.execute(
                """
                UPDATE simulated_trades
                SET status = 'expired',
                    closed_at = %s,
                    close_price = %s,
                    close_reason = 'expiry',
                    final_pnl_percent = %s,
                    current_pnl_percent = %s,
                    latest_price = %s,
                    raw_payload = %s,
                    updated_at = %s
                WHERE id = %s
                AND status IN ('active', 'tp1_hit', 'tp2_hit', 'trailing_active')
                AND closed_at IS NULL
                """,
                (now, close_price, final_pnl, final_pnl, close_price, json.dumps(trade_payload, separators=(",", ":"), default=str), now, trade_id),
            )
            if cursor.rowcount > 0:
                summary["trades_expired"] += 1
                summary["trades_updated"] += 1
            connection.commit()
        except Exception:
            connection.rollback()
            raise
        finally:
            if cursor:
                cursor.close()
            connection.close()

    def _insert_expired_event(self, cursor, trade, now, close_price, previous_price, final_pnl, previous_status):
        cursor.execute(
            """
            INSERT INTO trade_events
                (simulated_trade_id, trade_plan_id, scan_run_id, scan_result_id, candidate_watchlist_id, spot_symbol_id,
                 coindcx_symbol, event_type, event_time, event_price, previous_price, trigger_price,
                 actual_price_move_percent, pnl_percent, max_gain_percent, max_drawdown_percent,
                 previous_status, new_status, message, raw_payload, created_at, updated_at)
            VALUES (%s, %s, %s, %s, %s, %s, %s, 'EXPIRED', %s, %s, %s, NULL, %s, %s, %s, %s, %s, 'expired', %s, %s, %s, %s)
            """,
            (
                trade.get("id"), trade.get("trade_plan_id"), trade.get("scan_run_id"), trade.get("scan_result_id"),
                trade.get("candidate_watchlist_id"), trade.get("spot_symbol_id"), trade.get("coindcx_symbol"), now,
                close_price, previous_price, final_pnl, final_pnl, safe_float(trade.get("max_gain_percent")),
                safe_float(trade.get("max_drawdown_percent")), previous_status, EXPIRED_MESSAGE,
                json.dumps(self._event_payload(trade, now, close_price, final_pnl, previous_status), separators=(",", ":"), default=str),
                now, now,
            ),
        )

    def _event_payload(self, trade, now, close_price, final_pnl, previous_status):
        return {
            "source": "trade_expiry_monitor",
            "simulation_only": True,
            "expiry": {
                "expires_at": self._format_dt(trade.get("expires_at")),
                "closed_at": self._format_dt(now),
                "entry_price": safe_float(trade.get("entry_price")),
                "close_price": close_price,
                "final_pnl_percent": final_pnl,
                "previous_status": previous_status,
                "close_reason": "expiry",
            },
        }

    def _merged_trade_payload(self, raw_payload: Any, now, trade, close_price, final_pnl):
        payload = self._decode_payload(raw_payload)
        payload["expiry_monitor"] = {
            "last_checked_at": self._format_dt(now),
            "expired": True,
            "expires_at": self._format_dt(trade.get("expires_at")),
            "closed_at": self._format_dt(now),
            "close_price": close_price,
            "final_pnl_percent": final_pnl,
            "close_reason": "expiry",
        }
        return payload

    def _expired_event_exists(self, trade_id):
        return fetch_one("SELECT id FROM trade_events WHERE simulated_trade_id = %s AND event_type = 'EXPIRED' LIMIT 1", (trade_id,)) is not None

    def _skip(self, summary, trade_id, reason):
        summary["skipped"] += 1
        summary["errors"].append({"trade_id": trade_id, "error": reason})

    @staticmethod
    def _decode_payload(raw_payload: Any) -> dict:
        if isinstance(raw_payload, dict):
            return dict(raw_payload)
        if raw_payload:
            try:
                decoded = json.loads(raw_payload)
                return decoded if isinstance(decoded, dict) else {}
            except (TypeError, ValueError):
                return {}
        return {}

    @staticmethod
    def _format_dt(value):
        if value is None:
            return None
        if hasattr(value, "strftime"):
            return value.strftime("%Y-%m-%d %H:%M:%S")
        return str(value)

    @staticmethod
    def _write_health(status, message, meta):
        try:
            write_health_log(SERVICE_NAME, status, message, meta)
        except Exception:
            logger.exception("Failed to write trade expiry monitor health log")
