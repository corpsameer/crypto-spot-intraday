import json
import sys
from importlib.util import find_spec
from pathlib import Path

PYTHON_ROOT = Path(__file__).resolve().parents[1]
if str(PYTHON_ROOT) not in sys.path:
    sys.path.insert(0, str(PYTHON_ROOT))


def main() -> int:
    if find_spec("mysql") is None or find_spec("mysql.connector") is None:
        print(
            "Market context engine failed: missing mysql-connector-python dependency. "
            "Run `pip install -r requirements.txt` from the python folder.",
            file=sys.stderr,
        )
        return 1

    from cryptospot.market_context_engine import MarketContextEngine

    try:
        engine = MarketContextEngine()
        summary = engine.run()
        print("Market context engine summary:")
        print(json.dumps(summary, indent=2, default=str))
        return 0 if summary.get("snapshot_inserted") else 1
    except Exception as exc:
        print(f"Market context engine failed: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
