from datetime import datetime, timedelta
from typing import Any

from cryptospot.db import execute, fetch_one
from cryptospot.health import write_health_log
from cryptospot.logger import get_logger
from cryptospot.settings import get_setting

SERVICE_NAME = "data_cleanup"

DEFAULT_RETENTION_DAYS = {
    "retention.candles_1m_days": 3,
    "retention.candles_5m_days": 7,
    "retention.candles_15m_days": 14,
    "retention.candles_1h_days": 45,
    "retention.candles_4h_days": 90,
    "retention.scanner_metrics_days": 14,
    "retention.market_snapshots_days": 30,
    "retention.system_health_logs_days": 30,
}

CANDLE_TIMEFRAMES = {
    "1m": "retention.candles_1m_days",
    "5m": "retention.candles_5m_days",
    "15m": "retention.candles_15m_days",
    "1h": "retention.candles_1h_days",
    "4h": "retention.candles_4h_days",
}

logger = get_logger(__name__)


class DataCleanupService:
    def run(self, dry_run: bool = False) -> dict:
        summary = self._empty_summary(dry_run)

        try:
            now = datetime.now()
            self._cleanup_candles(summary, now, dry_run)
            self._cleanup_table(
                summary=summary,
                summary_key="scanner_metrics",
                setting_key="retention.scanner_metrics_days",
                table_name="scanner_metrics",
                timestamp_column="metric_time",
                now=now,
                dry_run=dry_run,
            )
            self._cleanup_table(
                summary=summary,
                summary_key="market_snapshots",
                setting_key="retention.market_snapshots_days",
                table_name="market_snapshots",
                timestamp_column="snapshot_time",
                now=now,
                dry_run=dry_run,
            )
            self._cleanup_table(
                summary=summary,
                summary_key="system_health_logs",
                setting_key="retention.system_health_logs_days",
                table_name="system_health_logs",
                timestamp_column="checked_at",
                now=now,
                dry_run=dry_run,
            )

            total_rows = self._total_rows(summary)
            if summary["errors"]:
                status = "warning"
                message = (
                    f"Data cleanup completed with {len(summary['errors'])} error(s); "
                    f"dry_run={str(dry_run).lower()}; total_rows_deleted={total_rows}."
                )
            else:
                status = "ok"
                label = "rows_that_would_be_deleted" if dry_run else "total_rows_deleted"
                message = f"Data cleanup completed; dry_run={str(dry_run).lower()}; {label}={total_rows}."

            write_health_log(SERVICE_NAME, status, message, summary)
            return summary
        except Exception as exc:
            logger.exception("Data cleanup failed before cleanup could complete")
            summary["fatal_error"] = str(exc)
            summary["errors"].append({"section": "fatal", "error": str(exc)})
            try:
                write_health_log(SERVICE_NAME, "error", str(exc), summary)
            except Exception:
                logger.exception("Failed to write data cleanup fatal health log")
            return summary

    def _empty_summary(self, dry_run: bool) -> dict:
        return {
            "dry_run": bool(dry_run),
            "candles": {},
            "scanner_metrics": {},
            "market_snapshots": {},
            "system_health_logs": {},
            "errors": [],
        }

    def _cleanup_candles(self, summary: dict, now: datetime, dry_run: bool) -> None:
        for timeframe, setting_key in CANDLE_TIMEFRAMES.items():
            retention_days = self._retention_days(setting_key)
            cutoff = self._cutoff(now, retention_days)
            section = {"retention_days": retention_days, "cutoff": cutoff, "rows_deleted": 0}
            summary["candles"][timeframe] = section

            try:
                if dry_run:
                    section["rows_deleted"] = self._count_rows(
                        "SELECT COUNT(*) AS count FROM candles WHERE timeframe = %s AND candle_time < %s",
                        (timeframe, cutoff),
                    )
                else:
                    section["rows_deleted"] = execute(
                        "DELETE FROM candles WHERE timeframe = %s AND candle_time < %s",
                        (timeframe, cutoff),
                    )
            except Exception as exc:
                self._record_error(summary, f"candles.{timeframe}", exc)

    def _cleanup_table(
        self,
        summary: dict,
        summary_key: str,
        setting_key: str,
        table_name: str,
        timestamp_column: str,
        now: datetime,
        dry_run: bool,
    ) -> None:
        retention_days = self._retention_days(setting_key)
        cutoff = self._cutoff(now, retention_days)
        section = {"retention_days": retention_days, "cutoff": cutoff, "rows_deleted": 0}
        summary[summary_key] = section

        try:
            if dry_run:
                section["rows_deleted"] = self._count_rows(
                    f"SELECT COUNT(*) AS count FROM {table_name} WHERE {timestamp_column} < %s",
                    (cutoff,),
                )
            else:
                section["rows_deleted"] = execute(
                    f"DELETE FROM {table_name} WHERE {timestamp_column} < %s",
                    (cutoff,),
                )
        except Exception as exc:
            self._record_error(summary, summary_key, exc)

    def _retention_days(self, setting_key: str) -> int:
        default = DEFAULT_RETENTION_DAYS[setting_key]
        try:
            value = get_setting(setting_key, default)
            days = int(value)
            return days if days >= 1 else default
        except (TypeError, ValueError):
            return default

    def _cutoff(self, now: datetime, retention_days: int) -> str:
        return (now - timedelta(days=retention_days)).strftime("%Y-%m-%d %H:%M:%S")

    def _count_rows(self, query: str, params: tuple[Any, ...]) -> int:
        row = fetch_one(query, params)
        if not row:
            return 0
        return int(row.get("count") or 0)

    def _record_error(self, summary: dict, section: str, exc: Exception) -> None:
        error = {"section": section, "error": str(exc)}
        summary["errors"].append(error)
        logger.warning("Data cleanup section failed: %s: %s", section, exc)

    def _total_rows(self, summary: dict) -> int:
        total = 0
        for timeframe_summary in summary.get("candles", {}).values():
            total += int(timeframe_summary.get("rows_deleted") or 0)
        for key in ("scanner_metrics", "market_snapshots", "system_health_logs"):
            total += int(summary.get(key, {}).get("rows_deleted") or 0)
        return total
