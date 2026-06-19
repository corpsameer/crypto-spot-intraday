#!/usr/bin/env python3
"""Read-only duplicate/cooldown opportunity health report."""
import json
import sys
from datetime import datetime, timedelta
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from cryptospot.db import fetch_all  # noqa: E402
from cryptospot.portfolio_gate import PortfolioGate, _dt  # noqa: E402

OPEN_STATUSES = "'active','tp1_hit','tp2_hit','trailing_active'"
PENDING_STATUSES = "'pending','watching','triggered'"


def rows(sql, params=None):
    return fetch_all(sql, params or ())


def main():
    duplicate_open_trades = rows(f"""
        SELECT coindcx_symbol, COUNT(*) cnt, GROUP_CONCAT(id ORDER BY id DESC) ids
        FROM simulated_trades
        WHERE status IN ({OPEN_STATUSES}) AND closed_at IS NULL
        GROUP BY coindcx_symbol HAVING cnt > 1
    """)
    duplicate_pending_plans = rows(f"""
        SELECT coindcx_symbol, COUNT(*) cnt, GROUP_CONCAT(id ORDER BY id DESC) ids
        FROM trade_plans
        WHERE status IN ({PENDING_STATUSES})
          AND converted_at IS NULL
          AND (capital_released_at IS NULL OR capital_released_at = '')
          AND COALESCE(portfolio_status, '') NOT IN ('released','rejected')
        GROUP BY coindcx_symbol HAVING cnt > 1
    """)
    combined = rows(f"""
        SELECT symbol AS coindcx_symbol, SUM(open_trade_count) open_trade_count, SUM(pending_plan_count) pending_plan_count,
               SUM(open_trade_count + pending_plan_count) total_active_opportunities
        FROM (
          SELECT coindcx_symbol symbol, COUNT(*) open_trade_count, 0 pending_plan_count
          FROM simulated_trades WHERE status IN ({OPEN_STATUSES}) AND closed_at IS NULL GROUP BY coindcx_symbol
          UNION ALL
          SELECT coindcx_symbol symbol, 0 open_trade_count, COUNT(*) pending_plan_count
          FROM trade_plans
          WHERE status IN ({PENDING_STATUSES}) AND converted_at IS NULL
            AND (capital_released_at IS NULL OR capital_released_at = '')
            AND COALESCE(portfolio_status, '') NOT IN ('released','rejected')
          GROUP BY coindcx_symbol
        ) x GROUP BY symbol HAVING total_active_opportunities > 1
    """)
    stuck_triggered_plans = rows("""
        SELECT id, coindcx_symbol, triggered_at, portfolio_status, updated_at
        FROM trade_plans
        WHERE status = 'triggered' AND converted_at IS NULL AND simulated_trade_id IS NULL
        ORDER BY triggered_at ASC, id ASC LIMIT 100
    """)
    gate = PortfolioGate()
    settings = gate._settings()
    closed_symbols = rows("""
        SELECT coindcx_symbol, MAX(closed_at) last_closed_at
        FROM simulated_trades
        WHERE closed_at IS NOT NULL AND NOT (status = 'expired' OR COALESCE(close_reason, exit_reason, '') IN ('expiry','expired'))
        GROUP BY coindcx_symbol ORDER BY last_closed_at DESC LIMIT 100
    """)
    symbols_in_cooldown = []
    for item in closed_symbols:
        cooldown = gate._cooldown(item.get('coindcx_symbol'), settings)
        if cooldown:
            symbols_in_cooldown.append({'coindcx_symbol': item.get('coindcx_symbol'), **cooldown})
    print(json.dumps({
        'duplicate_open_trades': duplicate_open_trades,
        'duplicate_pending_plans': duplicate_pending_plans,
        'duplicate_active_opportunities': combined,
        'stuck_triggered_plans': stuck_triggered_plans,
        'symbols_in_cooldown': symbols_in_cooldown,
    }, indent=2, default=str))


if __name__ == '__main__':
    main()
