from cryptospot.health import write_health_log
from cryptospot.logger import get_logger

SERVICE_NAME = "trade_expiry_monitor"
logger = get_logger(__name__)


class TradeExpiryMonitor:
    """Legacy no-op monitor.

    Simulated trades no longer expire by time. Opportunity expiry is handled by
    ScanCycleExpiryManager during full scans and never updates simulated_trades.
    """

    def run_once(self, limit: int = None) -> dict:
        summary = {
            "trades_loaded": 0,
            "trades_checked": 0,
            "events_created": 0,
            "trades_expired": 0,
            "trades_updated": 0,
            "skipped": 0,
            "errors": [],
            "disabled_reason": "simulated_trades_do_not_expire; scan-cycle opportunity expiry runs in scan_cycle_expiry_manager",
        }
        try:
            write_health_log(
                SERVICE_NAME,
                "ok",
                "Legacy simulated trade expiry monitor disabled; simulated trades do not expire by time or scan cycle",
                summary,
            )
        except Exception:
            logger.exception("Failed to write disabled trade expiry monitor health log")
        return summary
