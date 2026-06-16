import argparse
import json
import sys
from pathlib import Path

PYTHON_ROOT = Path(__file__).resolve().parents[1]
if str(PYTHON_ROOT) not in sys.path:
    sys.path.insert(0, str(PYTHON_ROOT))

from cryptospot.data_cleanup import DataCleanupService  # noqa: E402


def main() -> int:
    parser = argparse.ArgumentParser(description="Run one-shot data cleanup based on retention settings.")
    parser.add_argument("--dry-run", action="store_true", help="Count old rows without deleting them.")
    args = parser.parse_args()

    try:
        service = DataCleanupService()
        summary = service.run(dry_run=args.dry_run)
        print("Data cleanup summary:")
        print(json.dumps(summary, indent=2, default=str))

        if summary.get("fatal_error"):
            return 1
        return 0
    except Exception as exc:
        print(f"Data cleanup failed: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
