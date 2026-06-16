import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from cryptospot.db import fetch_one
from cryptospot.health import write_health_log


def main():
    try:
        row = fetch_one("SELECT COUNT(*) AS count FROM spot_symbols")
        count = row["count"] if row else 0
        print(f"spot_symbols count: {count}")
        write_health_log("python_db_test", "ok", f"DB connection test successful. spot_symbols count: {count}")
    except Exception as exc:
        print(f"DB connection test failed: {exc}")
        try:
            write_health_log("python_db_test", "error", str(exc))
        except Exception:
            pass
        raise


if __name__ == "__main__":
    main()
