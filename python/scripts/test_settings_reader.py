import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from cryptospot.settings import get_setting


SETTING_KEYS = [
    "scanner.watchlist_score_threshold",
    "scanner.strong_score_threshold",
    "trade.tp1_percent",
    "trade.tp2_percent",
    "system.real_trading_enabled",
]


def main():
    for key in SETTING_KEYS:
        value = get_setting(key)
        print(f"{key}: {value!r} ({type(value).__name__})")


if __name__ == "__main__":
    main()
