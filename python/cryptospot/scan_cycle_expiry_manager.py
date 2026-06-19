import json
from datetime import datetime
from decimal import Decimal
from typing import Any

from cryptospot.db import fetch_all, get_connection
from cryptospot.health import write_health_log
from cryptospot.logger import get_logger

SERVICE_NAME = "scan_cycle_expiry_manager"
EXPIRY_REASON = "new_scan_replaced"
WATCHLIST_ACTIVE_STATUSES = ("active", "open", "watchlist", "pending", "selected", "refreshed")
TRADE_PLAN_EXPIRABLE_STATUSES = ("pending", "watching")

logger = get_logger(__name__)


def json_default(value: Any):
    if isinstance(value, Decimal):
        return float(value)
    if isinstance(value, datetime):
        return value.strftime("%Y-%m-%d %H:%M:%S")
    return str(value)


class ScanCycleExpiryManager:
    def __init__(self, db=None, settings_reader=None):
        self.db = db
        self.settings_reader = settings_reader

    def expire_previous_scan_opportunities(self, current_scan_run_id: int) -> dict:
        summary = {
            "current_scan_run_id": int(current_scan_run_id),
            "watchlist_candidates_expired": 0,
            "trade_plans_expired": 0,
            "simulated_trades_expired": 0,
            "errors": [],
        }
        try:
            columns = {
                "candidate_watchlists": self._table_columns("candidate_watchlists"),
                "trade_plans": self._table_columns("trade_plans"),
                "simulated_trades": self._table_columns("simulated_trades"),
            }
            summary["watchlist_candidates_expired"] = self._expire_watchlist_candidates(current_scan_run_id, columns)
            summary["trade_plans_expired"] = self._expire_trade_plans(current_scan_run_id, columns)
            self._write_health("warning" if summary["errors"] else "ok", summary)
        except Exception as exc:
            logger.exception("Scan-cycle opportunity expiry failed")
            summary["errors"].append(str(exc))
            self._write_health("error", summary)
            raise
        return summary

    def _expire_watchlist_candidates(self, current_scan_run_id: int, columns: dict[str, set[str]]) -> int:
        cw_columns = columns["candidate_watchlists"]
        if "scan_run_id" not in cw_columns or "status" not in cw_columns:
            return 0
        rows = fetch_all(
            f"""
            SELECT id, raw_payload
            FROM candidate_watchlists cw
            WHERE cw.scan_run_id < %s
              AND cw.status IN ({self._placeholders(WATCHLIST_ACTIVE_STATUSES)})
              AND NOT EXISTS (
                  SELECT 1 FROM simulated_trades st
                  WHERE st.candidate_watchlist_id = cw.id
                  LIMIT 1
              )
              AND NOT EXISTS (
                  SELECT 1 FROM trade_plans tp
                  WHERE tp.candidate_watchlist_id = cw.id
                    AND (tp.status IN ('triggered', 'converted_to_trade') OR tp.simulated_trade_id IS NOT NULL OR tp.converted_at IS NOT NULL)
                  LIMIT 1
              )
            ORDER BY cw.scan_run_id ASC, cw.id ASC
            """,
            (current_scan_run_id, *WATCHLIST_ACTIVE_STATUSES),
        )
        return self._expire_rows("candidate_watchlists", rows, cw_columns, current_scan_run_id)

    def _expire_trade_plans(self, current_scan_run_id: int, columns: dict[str, set[str]]) -> int:
        tp_columns = columns["trade_plans"]
        if "scan_run_id" not in tp_columns or "status" not in tp_columns:
            return 0
        rows = fetch_all(
            f"""
            SELECT id, raw_payload
            FROM trade_plans tp
            WHERE tp.scan_run_id < %s
              AND tp.status IN ({self._placeholders(TRADE_PLAN_EXPIRABLE_STATUSES)})
              AND (tp.converted_at IS NULL)
              AND (tp.simulated_trade_id IS NULL)
              AND COALESCE(tp.portfolio_status, '') <> 'rejected'
              AND tp.status <> 'portfolio_rejected'
              AND NOT EXISTS (
                  SELECT 1 FROM simulated_trades st
                  WHERE st.trade_plan_id = tp.id
                  LIMIT 1
              )
            ORDER BY tp.scan_run_id ASC, tp.id ASC
            """,
            (current_scan_run_id, *TRADE_PLAN_EXPIRABLE_STATUSES),
        )
        return self._expire_rows("trade_plans", rows, tp_columns, current_scan_run_id)

    def _expire_rows(self, table: str, rows: list[dict], columns: set[str], current_scan_run_id: int) -> int:
        if not rows:
            return 0
        now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        updated = 0
        connection = get_connection()
        cursor = None
        try:
            cursor = connection.cursor()
            for row in rows:
                values = {"status": "expired", "updated_at": now}
                if "expiry_reason" in columns:
                    values["expiry_reason"] = EXPIRY_REASON
                if "expired_at" in columns:
                    values["expired_at"] = now
                if "raw_payload" in columns:
                    payload = self._loads(row.get("raw_payload"))
                    payload["expiry_reason"] = EXPIRY_REASON
                    payload["expired_by_scan_run_id"] = int(current_scan_run_id)
                    payload["expired_at"] = now
                    payload["scan_cycle_expiry_manager"] = {
                        "expired_by_scan_run_id": int(current_scan_run_id),
                        "expiry_reason": EXPIRY_REASON,
                        "expired_at": now,
                    }
                    values["raw_payload"] = json.dumps(payload, separators=(",", ":"), default=json_default)
                assignments = ", ".join(f"{key} = %s" for key in values)
                cursor.execute(f"UPDATE {table} SET {assignments} WHERE id = %s AND status <> 'expired'", (*values.values(), row["id"]))
                updated += cursor.rowcount
            connection.commit()
        except Exception:
            connection.rollback()
            raise
        finally:
            if cursor:
                cursor.close()
            connection.close()
        return updated

    def _table_columns(self, table: str) -> set[str]:
        rows = fetch_all("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s", (table,))
        return {row["COLUMN_NAME"] for row in rows}

    @staticmethod
    def _placeholders(values) -> str:
        return ", ".join(["%s"] * len(values))

    @staticmethod
    def _loads(value: Any) -> dict:
        if isinstance(value, dict):
            return dict(value)
        if value:
            try:
                decoded = json.loads(value)
                return decoded if isinstance(decoded, dict) else {}
            except (TypeError, ValueError):
                return {}
        return {}

    def _write_health(self, status: str, summary: dict) -> None:
        message = (
            f"Expired {summary.get('watchlist_candidates_expired', 0)} watchlist candidates and "
            f"{summary.get('trade_plans_expired', 0)} pending trade plans from older scans"
        )
        if summary.get("errors"):
            message += f"; errors {len(summary['errors'])}"
        try:
            write_health_log(SERVICE_NAME, status, message, summary)
        except Exception:
            logger.exception("Failed to write scan-cycle expiry health log")
