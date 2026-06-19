import argparse
import json
import os
import sys
import time
from datetime import datetime

CURRENT_DIR = os.path.dirname(os.path.abspath(__file__))
PYTHON_DIR = os.path.dirname(CURRENT_DIR)
if PYTHON_DIR not in sys.path:
    sys.path.insert(0, PYTHON_DIR)

try:
    from cryptospot.active_trade_monitor import ActiveTradeMonitor  # noqa: E402
    from cryptospot.breakout_entry_simulator import BreakoutEntrySimulator  # noqa: E402
    from cryptospot.pullback_entry_simulator import PullbackEntrySimulator  # noqa: E402
    from cryptospot.settings import get_setting  # noqa: E402
    from cryptospot.trade_event_monitor import TradeEventMonitor  # noqa: E402
    from cryptospot.trade_plan_trigger_monitor import TradePlanTriggerMonitor  # noqa: E402
    from cryptospot.trailing_monitor import TrailingMonitor  # noqa: E402
except Exception as exc:  # pragma: no cover - startup safety for CLI usage
    print(f"Fatal startup error importing realtime monitors: {exc}", file=sys.stderr)
    sys.exit(1)


def _default_interval() -> int:
    try:
        return int(get_setting("monitor.active_trade_refresh_seconds", 15) or 15)
    except Exception:
        return 15


def _timestamp() -> str:
    return datetime.now().strftime("%Y-%m-%d %H:%M:%S")


def _print_monitor_result(cycle: int, monitor_name: str, summary: dict) -> None:
    print(
        f"[{_timestamp()}] cycle={cycle} {monitor_name} "
        f"{json.dumps(summary, default=str, separators=(',', ':'))}",
        flush=True,
    )


def _print_monitor_error(cycle: int, monitor_name: str, exc: Exception) -> None:
    summary = {"error": str(exc), "errors": [str(exc)]}
    print(
        f"[{_timestamp()}] cycle={cycle} {monitor_name} "
        f"{json.dumps(summary, default=str, separators=(',', ':'))}",
        file=sys.stderr,
        flush=True,
    )


def _build_monitors(args: argparse.Namespace) -> list[tuple[str, object]]:
    monitors: list[tuple[str, object]] = []

    if not args.skip_plan_trigger:
        monitors.append(("trade_plan_trigger_monitor", TradePlanTriggerMonitor()))
    if not args.skip_entry_simulators:
        monitors.append(("breakout_entry_simulator", BreakoutEntrySimulator()))
        monitors.append(("pullback_entry_simulator", PullbackEntrySimulator()))
    if not args.skip_active_trade:
        monitors.append(("active_trade_monitor", ActiveTradeMonitor()))
    if not args.skip_events:
        monitors.append(("trade_event_monitor", TradeEventMonitor()))
    if not args.skip_trailing:
        monitors.append(("trailing_monitor", TrailingMonitor()))
    return monitors


def _run_cycle(cycle: int, monitors: list[tuple[str, object]], limit: int | None) -> None:
    for monitor_name, monitor in monitors:
        try:
            summary = monitor.run_once(limit=limit)
            _print_monitor_result(cycle, monitor_name, summary)
        except Exception as exc:
            _print_monitor_error(cycle, monitor_name, exc)


def main() -> int:
    parser = argparse.ArgumentParser(
        description="Run the lightweight realtime candidate/trade monitors in one Supervisor-friendly loop."
    )
    parser.add_argument("--interval", type=int, default=None, help="Loop interval in seconds")
    parser.add_argument("--limit", type=int, default=100, help="Per-monitor row limit per cycle")
    parser.add_argument("--once", action="store_true", help="Run one cycle and exit")
    parser.add_argument("--skip-plan-trigger", action="store_true", help="Skip trade plan trigger checks")
    parser.add_argument("--skip-entry-simulators", action="store_true", help="Skip breakout and pullback simulators")
    parser.add_argument("--skip-active-trade", action="store_true", help="Skip active simulated trade price updates")
    parser.add_argument("--skip-events", action="store_true", help="Skip TP1/TP2/SL event logging")
    parser.add_argument("--skip-trailing", action="store_true", help="Skip trailing stop monitor")
    args = parser.parse_args()

    interval = args.interval if args.interval is not None else _default_interval()
    interval = max(1, int(interval))

    try:
        monitors = _build_monitors(args)
    except Exception as exc:
        print(f"Fatal startup error creating realtime monitors: {exc}", file=sys.stderr)
        return 1

    if not monitors:
        print("No realtime monitors enabled; exiting", file=sys.stderr)
        return 1

    print(
        f"Starting realtime monitors loop interval={interval}s limit={args.limit} "
        f"monitors={[name for name, _ in monitors]}",
        flush=True,
    )

    cycle = 1
    try:
        while True:
            _run_cycle(cycle, monitors, args.limit)
            if args.once:
                return 0
            cycle += 1
            time.sleep(interval)
    except KeyboardInterrupt:
        print("Stopping realtime monitors loop", flush=True)
        return 0


if __name__ == "__main__":
    sys.exit(main())
