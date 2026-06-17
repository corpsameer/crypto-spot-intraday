#!/usr/bin/env python3
import argparse
import json
import sys
from datetime import date, datetime
from pathlib import Path

sys.path.append(str(Path(__file__).resolve().parents[1]))

from cryptospot.missed_gainer_analyzer import MissedGainerAnalyzer, json_default


def main() -> int:
    parser = argparse.ArgumentParser(description="Analyze daily gainer leaderboard rows against stored scanner data.")
    parser.add_argument("--date", dest="analysis_date", default=None, help="Analysis date YYYY-MM-DD. Defaults to today.")
    parser.add_argument("--quote", default="USDT", help="Quote filter from daily_gainer_leaderboard. Default: USDT.")
    parser.add_argument("--min-change", type=float, default=10.0, help="Minimum 24h change percent. Default: 10.")
    parser.add_argument("--limit", type=int, default=100, help="Maximum leaderboard rows to analyze. Default: 100.")
    args = parser.parse_args()

    try:
        summary = MissedGainerAnalyzer().run(args.analysis_date, args.quote, args.min_change, args.limit)
        print("Missed gainer analyzer summary")
        print(json.dumps(summary, indent=2, default=json_default))
        return 0
    except Exception as exc:
        print(f"Missed gainer analyzer failed: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
