#!/usr/bin/env python3
import argparse
import json
import sys
from pathlib import Path

sys.path.append(str(Path(__file__).resolve().parents[1]))

from cryptospot.daily_gainer_leaderboard import DailyGainerLeaderboardBuilder, json_default


def main() -> int:
    parser = argparse.ArgumentParser(description="Build the CoinDCX daily gainer leaderboard once.")
    parser.add_argument("--date", dest="leaderboard_date", default=None, help="Leaderboard date in YYYY-MM-DD format. Defaults to today.")
    parser.add_argument("--quote", default="USDT", help="Quote filter: USDT, INR, or ALL. Defaults to USDT.")
    parser.add_argument("--limit", type=int, default=100, help="Maximum number of gainer rows to store. Defaults to 100.")
    args = parser.parse_args()

    try:
        summary = DailyGainerLeaderboardBuilder().run(args.leaderboard_date, args.quote, args.limit)
        print("Daily gainer leaderboard summary")
        print(json.dumps(summary, indent=2, default=json_default))
        return 0
    except Exception as exc:
        print(f"Daily gainer leaderboard failed: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
