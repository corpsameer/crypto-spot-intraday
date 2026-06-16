import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from cryptospot.health import write_health_log


def main():
    write_health_log(
        "python_test",
        "ok",
        "Python health log test successful",
        {"source": "scripts/test_health_log.py", "test": True},
    )
    print("Health log written successfully.")


if __name__ == "__main__":
    main()
