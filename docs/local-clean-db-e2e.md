# Local Clean DB End-to-End Test

This document is the local clean-database E2E checklist and the latest local execution report for the Crypto Spot Intraday Gainer Scanner MVP. It is intentionally scan-based: full-market scans are run manually for local E2E and later by Laravel scheduler; realtime monitoring is limited to lightweight trade-plan and simulated-trade lifecycle monitors.

## Safety rules

- Run this checklist only against a local/dev database.
- Do not run `migrate:fresh --seed` against VPS, staging, production, or any database containing irreplaceable data.
- Do not document or commit production credentials.
- CoinDCX integration must use public APIs only.
- No private CoinDCX API keys are required.
- No real trading, private endpoints, or order placement logic are part of this E2E.
- The realtime monitor command is `python scripts/run_realtime_monitors_loop.py --once --interval 5 --limit 50` and must not run full scans, all-coin candle collection, all-coin orderbook collection, daily gainers, or missed-gainer analysis continuously.

## Confirmed project commands and routes

### Laravel commands

- Universe sync command: `php artisan cryptospot:sync-spot-universe`
- Full scan command: `php artisan cryptospot:scan`
- Daily gainer command: `php artisan cryptospot:daily-gainers`
- Missed gainer command: `php artisan cryptospot:missed-gainers`
- Retention cleanup command: `php artisan cryptospot:cleanup`

### Scheduler expectations

`routes/console.php` should schedule:

- `cryptospot:scan` hourly in `Asia/Kolkata`.
- `cryptospot:daily-gainers` at minute 15 every 4 hours in `Asia/Kolkata`.
- `cryptospot:missed-gainers` at minute 20 every 4 hours in `Asia/Kolkata`.
- `cryptospot:cleanup` daily at 03:30 in `Asia/Kolkata`.

### UI routes to check

- `/cryptospot/login`
- `/cryptospot/dashboard`
- `/cryptospot/scans/latest`
- `/cryptospot/watchlist`
- `/cryptospot/trade-plans`
- `/cryptospot/simulated-trades`
- `/cryptospot/daily-gainers`
- `/cryptospot/missed-gainers`
- `/cryptospot/analytics/scanner-performance`
- `/cryptospot/analytics/trade-performance`
- `/cryptospot/analytics/score-buckets`
- `/cryptospot/analytics/setup-types`
- `/cryptospot/system-health`
- `/cryptospot/daily-review`

## Local E2E checklist

### 1. Confirm environment

Run from repo root:

```bash
test -f .env && echo ".env exists" || echo ".env missing"
php artisan --version
php artisan optimize:clear
cd python
python --version
python -c "import mysql.connector; print('mysql ok')"
```

Check `.env` manually before resetting the database:

```bash
sed -n '1,120p' .env
```

Required local settings:

- `APP_ENV=local`
- `APP_URL` points to the local app, for example `http://localhost/cryptospot`
- `DB_DATABASE` is a local/dev database, for example `crypto_spot_intraday`
- CoinDCX base URLs are public endpoints only.
- No private API key variables are required for scanner operation.

If dependencies are missing, install with:

```bash
composer install
cp .env.example .env
php artisan key:generate
cd python
python -m venv venv
source venv/bin/activate
pip install -r requirements.txt
cp .env.example .env
```

### 2. Clean DB reset

Only after confirming the DB is local/dev, run:

```bash
php artisan migrate:fresh --seed
```

If required seed data is split out, also run:

```bash
php artisan db:seed
```

Seed expectations:

- Login user exists.
- `app_settings` rows exist.
- Scanner settings exist.
- Manual/scan settings exist.
- Trailing settings exist.
- API token exists if API middleware requires it.

Verify tables:

```sql
SHOW TABLES;
```

Required tables:

- `users`
- `app_settings`
- `spot_symbols`
- `scan_runs`
- `scan_results`
- `candidate_watchlists`
- `trade_plans`
- `simulated_trades`
- `trade_events`
- `daily_gainer_leaderboard`
- `missed_gainers`
- `system_health_logs`
- `market_snapshots`
- `candles`
- `scanner_metrics`

Stop and fix migrations if any required table is missing.

### 3. Verify login and empty-state UI

Start the local Laravel server if needed:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Open `/cryptospot/login`, login with the seeded user, and verify `/cryptospot/dashboard` loads. Then check every UI route listed above. Expected result: pages load, empty states render, no SQL errors, no undefined variables, and no 500 responses.

### 4. Sync CoinDCX spot universe

```bash
php artisan cryptospot:sync-spot-universe
```

Verify:

```sql
SELECT COUNT(*) AS total, SUM(is_active = 1) AS active, SUM(quote_asset = 'USDT') AS usdt_count FROM spot_symbols;
SELECT id, coindcx_symbol, api_pair, base_asset, quote_asset, is_active FROM spot_symbols ORDER BY id ASC LIMIT 20;
```

Expected: active symbols > 0, USDT symbols > 0, and `api_pair` populated where CoinDCX provides it.

### 5. Run one full scan

```bash
php artisan cryptospot:scan
```

Fallback if the Artisan wrapper fails:

```bash
cd python
python scripts/run_manual_scan_once.py
```

Verify:

```sql
SELECT id, status, started_at, finished_at FROM scan_runs ORDER BY id DESC LIMIT 5;
SELECT COUNT(*) FROM scan_results;
SELECT scan_run_id, COUNT(*) AS rows_count, SUM(selected_for_watchlist = 1) AS selected_count, SUM(final_score IS NOT NULL) AS scored_count FROM scan_results GROUP BY scan_run_id ORDER BY scan_run_id DESC LIMIT 5;
```

Expected: scan run created, scan results created, BTC/ETH market snapshot created, candidate-only liquidity/candles used, and no fatal errors.

### 6. Verify watchlist and trade plans

```sql
SELECT COUNT(*) FROM candidate_watchlists;
SELECT status, COUNT(*) FROM trade_plans GROUP BY status;
SELECT id, coindcx_symbol, status, entry_strategy, planned_entry_price, trigger_price, tp1_price, tp2_price, sl_price, expires_at FROM trade_plans ORDER BY id DESC LIMIT 20;
```

Expected: watchlist rows exist if scan selected candidates; trade plans exist for selected candidates; each plan has entry, TP, SL, and expiry fields populated.

### 7. Run realtime monitors once

```bash
cd python
python scripts/run_realtime_monitors_loop.py --once --interval 5 --limit 50
```

Expected monitors:

- `trade_plan_trigger_monitor`
- `breakout_entry_simulator`
- `pullback_entry_simulator`
- `active_trade_monitor`
- `trade_event_monitor`
- `trailing_monitor`
- `trade_expiry_monitor`

Verify health logs:

```sql
SELECT service_name, status, message, checked_at FROM system_health_logs ORDER BY checked_at DESC LIMIT 30;
```

It is acceptable for triggered plans, created trades, or active trades to be 0 when live prices do not cross plan levels.

### 8. Controlled local trigger test if no simulated trade exists

Only if `simulated_trades` is empty after the realtime monitor, use local/dev DB only:

1. Inspect trade-plan columns.
2. Pick one open trade plan.
3. Use the schema-compatible fields expected by the entry simulators to mark it triggered, or adjust the local trigger price close to the latest public price.
4. Rerun the realtime monitor once.
5. Confirm a simulated trade and `ENTRY_TRIGGERED` event were created.

Do not fake more than needed; this is only to verify lifecycle UI and monitor idempotency.

Verify:

```sql
SELECT COUNT(*) FROM simulated_trades;
SELECT id, coindcx_symbol, status, entry_strategy, entry_price, latest_price, tp1_price, tp2_price, sl_price, current_pnl_percent FROM simulated_trades ORDER BY id DESC LIMIT 10;
SELECT event_type, COUNT(*) FROM trade_events GROUP BY event_type;
```

### 9. Run active trade lifecycle checks and idempotency check

Run twice:

```bash
cd python
python scripts/run_active_trade_monitor_once.py --limit 50
python scripts/run_trade_event_monitor_once.py --limit 50
python scripts/run_trailing_monitor_once.py --limit 50
python scripts/run_trade_expiry_monitor_once.py --limit 50
```

Duplicate lifecycle event check:

```sql
SELECT simulated_trade_id, event_type, COUNT(*) AS cnt FROM trade_events GROUP BY simulated_trade_id, event_type HAVING cnt > 1;
```

Expected: no duplicate lifecycle events.

### 10. Run daily gainer leaderboard

```bash
php artisan cryptospot:daily-gainers
```

Fallback:

```bash
cd python
python scripts/run_daily_gainer_leaderboard_once.py --quote USDT --limit 100
```

Verify:

```sql
SELECT leaderboard_date, quote_filter, COUNT(*) AS cnt, MIN(rank) AS min_rank, MAX(rank) AS max_rank, MAX(change_24h_percent) AS max_change FROM daily_gainer_leaderboard GROUP BY leaderboard_date, quote_filter ORDER BY leaderboard_date DESC;
SELECT leaderboard_date, quote_filter, coindcx_symbol, COUNT(*) AS cnt FROM daily_gainer_leaderboard GROUP BY leaderboard_date, quote_filter, coindcx_symbol HAVING cnt > 1;
```

Expected: rows exist, ranks start at 1, and duplicate query is empty.

### 11. Run missed gainer analyzer

```bash
php artisan cryptospot:missed-gainers
```

Fallback:

```bash
cd python
python scripts/run_missed_gainer_analyzer_once.py --quote USDT --min-change 10 --limit 100
```

Verify:

```sql
SELECT analysis_date, COUNT(*) AS cnt, SUM(miss_type = 'missed_completely') AS missed_completely, SUM(miss_type = 'captured_not_selected') AS captured_not_selected, SUM(miss_type = 'selected_no_trade_plan') AS selected_no_trade_plan, SUM(miss_type = 'trade_plan_not_triggered') AS trade_plan_not_triggered, SUM(miss_type = 'captured_trade_created') AS captured_trade_created FROM missed_gainers GROUP BY analysis_date ORDER BY analysis_date DESC;
SELECT analysis_date, coindcx_symbol, COUNT(*) AS cnt FROM missed_gainers GROUP BY analysis_date, coindcx_symbol HAVING cnt > 1;
```

Expected: rows exist when leaderboard has 10%+ gainers, no duplicates, and no errors.

### 12. Run cleanup

```bash
php artisan cryptospot:cleanup
```

Fallback:

```bash
cd python
python scripts/run_data_cleanup_once.py
```

Verify:

```sql
SELECT service_name, status, message, checked_at FROM system_health_logs WHERE service_name = 'retention_cleanup' ORDER BY checked_at DESC LIMIT 5;
```

### 13. Verify scheduler commands

```bash
php artisan list | grep cryptospot
php artisan schedule:list
```

Do not start cron locally unless specifically testing cron integration.

## Latest local execution report

- Date/time of test: 2026-06-18 UTC.
- DB reset command used: not run, because the local environment failed preflight before database reset.
- Migration/seed result: not run. `php artisan --version` failed because Laravel dependencies are not installed (`vendor/autoload.php` missing).
- Environment result:
  - `.env`: missing.
  - Laravel dependencies: missing; run `composer install`.
  - Python version: `Python 3.14.4`.
  - Python MySQL dependency: passed; `import mysql.connector` printed `mysql ok`.
  - Python venv: not confirmed in this checkout; create one with `cd python && python -m venv venv && source venv/bin/activate && pip install -r requirements.txt` if missing.
- Universe sync result: not run due missing Laravel `.env` and `vendor/`.
- Scan result summary: not run due missing Laravel `.env` and `vendor/`.
- Watchlist/trade plan summary: not run.
- Realtime monitor summary: not run; skipped because the clean DB had not been migrated/seeded.
- Simulated trade summary: not run.
- Daily gainer result: not run.
- Missed gainer result: not run.
- Cleanup result: not run.
- Scheduler command result: source inspection confirms scheduled commands in `routes/console.php`, but `php artisan schedule:list` could not run until dependencies are installed.
- UI pages checked: source inspection confirms route definitions; browser checks were not run because the Laravel app cannot boot without `vendor/` and `.env`.
- Known issues:
  - Missing `.env` blocks environment confirmation and safe DB reset.
  - Missing Composer dependencies block all Artisan commands.
- Ready for VPS deployment: no.

## Remediation before rerun

Run these commands from the repository root, then rerun this checklist:

```bash
composer install
cp .env.example .env
php artisan key:generate
# Edit .env and confirm APP_ENV=local and DB_DATABASE is local/dev only.
cd python
python -m venv venv
source venv/bin/activate
pip install -r requirements.txt
cp .env.example .env
```

After confirming `.env` points to a local/dev DB, run `php artisan migrate:fresh --seed` and continue with the checklist.
