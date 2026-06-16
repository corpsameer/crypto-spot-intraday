import json
import sys
from pathlib import Path

PYTHON_ROOT = Path(__file__).resolve().parents[1]
if str(PYTHON_ROOT) not in sys.path:
    sys.path.insert(0, str(PYTHON_ROOT))


def main() -> int:
    try:
        from cryptospot.ticker_snapshot_collector import TickerSnapshotCollector

        summary = TickerSnapshotCollector().run()
    except Exception as exc:
        print(f"Ticker snapshot collector failed: {exc}")
        return 1

    print("Ticker snapshot collector summary:")
    print(json.dumps(summary, indent=2, default=str))
    return 0


if __name__ == "__main__":
    sys.exit(main())
