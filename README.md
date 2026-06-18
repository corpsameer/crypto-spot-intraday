# Crypto Spot Intraday Gainer Scanner

Crypto Spot Intraday Gainer Scanner is a personal-use CoinDCX spot intraday / 2–3 day research and simulation tool.

This is a personal-use simulation/research tool. It does not place real trades. Real trading is disabled in the MVP.

## Tech Stack

- Laravel
- Blade
- MySQL
- Python for future data/scanner tasks
- CoinDCX spot market research only

## Route Prefix

All application routes are served under `/cryptospot`.

Important routes:

- `GET /cryptospot`
- `GET /cryptospot/login`
- `POST /cryptospot/login`
- `GET /cryptospot/dashboard`
- `POST /cryptospot/logout`

No public registration route is provided.

## Local Setup

Install PHP dependencies:

```bash
composer install
```

Create the local environment file and application key:

```bash
cp .env.example .env
php artisan key:generate
```

Create the MySQL database if it does not already exist:

```sql
CREATE DATABASE crypto_spot_intraday CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Run migrations and seed the default admin user:

```bash
php artisan migrate --seed
```

Review routes:

```bash
php artisan route:list
```

Serve locally:

```bash
php artisan serve
```

Then open:

```text
http://127.0.0.1:8000/cryptospot
```

## Default Login

- Email: `admin@example.com`
- Password: `password`

The password is hashed by the Laravel `Hash` facade in the database seeder.

## Environment

The default local environment targets MySQL database `crypto_spot_intraday` with credentials configured in `.env`. Do not hardcode database credentials in application code.

## MVP Scope

Task 1 only sets up the Laravel foundation, single-user login, dashboard shell, and module placeholders. CoinDCX API integration, scanners, scoring, simulated trades, analytics, and real market data collection are intentionally not implemented yet.

## Troubleshooting

If an early migration attempt failed with `Specified key was too long`, drop the partially created local tables or recreate the local development database, then run migrations again:

```sql
DROP DATABASE crypto_spot_intraday;
CREATE DATABASE crypto_spot_intraday CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
php artisan migrate --seed
```

## CoinDCX Spot Universe Sync

Task 3 adds a CoinDCX public market sync for the local `spot_symbols` table.

Configure the public API base URL in `.env` if needed:

```bash
COINDCX_PUBLIC_BASE_URL=https://api.coindcx.com
```

Run the sync manually from the CLI:

```bash
php artisan cryptospot:sync-spot-universe
```

Or sign in and open:

```text
http://127.0.0.1:8000/cryptospot/spot-symbols
```

The sync only stores spot symbol metadata and writes a system health log entry for each attempt. It does not poll prices, candles, candidates, scores, simulated trades, or real trades.

## CoinDCX market-data pair compatibility

CoinDCX symbols are stored with two identifiers:

- `coindcx_symbol`: the normalized scanner/display symbol used for matching, joins, and scanner logic, for example `BTCUSDT`.
- `api_pair`: the raw CoinDCX market-data pair from Markets Details, for example `B-BTC_USDT`.

CoinDCX orderbook and candle public market-data endpoints must use `spot_symbols.api_pair` rather than the normalized `coindcx_symbol`. Re-run the migration and universe sync to add/backfill this value:

```bash
php artisan migrate
php artisan cryptospot:sync-universe
```

Python compatibility checks and collectors:

```bash
cd python
python scripts/test_market_pair_resolution.py
python scripts/run_candle_collection_once.py --limit 3 --timeframes 1m
```

The next orderbook/liquidity task is expected to add this command, which should also use `api_pair`:

```bash
python scripts/run_orderbook_collection_once.py --quote USDT --limit 3 --target-notional 100
```

## Scheduled Scan Workflow

The MVP workflow is scan-based rather than a continuous all-coin scanner. Full-market scans run manually or at configured scan times later, such as 9:00 AM IST, 2:00 PM IST, 7:00 PM IST, and 10:30 PM IST.

The planned workflow is:

```text
scan_run -> scan_results -> candidate_watchlist -> trade_plan -> simulated_trade -> trade_events -> analytics
```

Task 10.1 adds the database foundation for this workflow with these tables:

- `scan_runs` stores each manual or scheduled full-market scan execution and its summary counts/config snapshot.
- `scan_results` stores per-symbol scan outcomes, including prefilter status, copied metric fields, scoring placeholders, and suggested trade setup fields.
- `trade_plans` stores pending scanner/manual trade setups before any simulated trade is triggered.

Between full scans, the MVP should not continuously fetch candles, score every coin, or poll orderbooks for the full market. Later continuous processes may monitor only shortlisted candidates, pending trade plans, active simulated trades, and system health. Active simulated trades may be monitored continuously because TP, SL, trailing stops, and expiry can happen at any time.

This section documents schema support only. It does not add a scan runner, prefilter engine, scoring, candidate creation, trade-plan trigger monitoring, simulated trade creation, CoinDCX private APIs, API keys, or real trading.

### Scheduled Scan Settings

Task 10.2 adds configuration for scheduled/manual scan workflow settings in `app_settings`. Full-market scans are scheduled/manual only, with suggested defaults of `09:00`, `14:00`, `19:00`, and `22:30` IST stored in `scan.scheduled_times`. `scan.default_quote_filter` controls the default quote asset, while `prefilter.*` settings control which symbols can proceed to the future candle, metrics, and scoring stages.

The `monitor.*` settings are only for lightweight candidate, trade-plan, active simulated-trade, and system-health monitoring. Continuous all-coin candle fetching, all-coin scoring, and all-coin orderbook polling are not part of the MVP.

Seed and verify these settings with:

```bash
php artisan db:seed --class=AppSettingSeeder
cd python
python scripts/test_scan_settings.py
```

Task 10.2 is settings-only. It does not add a scan runner, prefilter engine, scoring, candidate creation, trade-plan creation, private CoinDCX APIs, API keys, or real trading.


## Simulated trade foundation

Task 21 adds the database and Laravel model foundation for future spot-only simulated trading.

- The `simulated_trades` table stores one simulated trade after a pending trade plan is triggered in a later task.
- The `trade_events` table stores atomic simulation events such as entry triggered, TP1/TP2 hit, SL hit, trailing updates, expiry, cancellation, close, error, and metric updates.
- This foundation includes schema columns, model fillable/cast definitions, and relationships back to trade plans, scan runs, scan results, candidate watchlists, spot symbols, and scanner metrics.
- No simulated trade creation, trigger monitoring, active trade monitoring, TP/SL processing, trailing-stop processing, or expiry-close logic is implemented yet.
- No real trading exists, and the application does not use private CoinDCX APIs or API keys.

## Daily gainer leaderboard

Task 30 adds a manual/scheduled one-shot daily gainer leaderboard. It fetches CoinDCX spot ticker data once per run, stores the top actual 24h gainers for a date/quote filter, and records lightweight scan-match fields for later missed-gainer analysis. It does not run as a continuous all-coin scanner and does not fetch candles or orderbooks.

```bash
cd python
python scripts/run_daily_gainer_leaderboard_once.py --quote USDT --limit 100
```

### Missed gainer analyzer

Task 31 adds a stored-data-only missed gainer analyzer. After the daily gainer leaderboard has been built, run the analyzer to classify actual top gainers against scanner capture, watchlist selection, trade plan creation, and simulated trade creation:

```bash
cd python
python scripts/run_missed_gainer_analyzer_once.py --quote USDT --min-change 10 --limit 100
```

The analyzer populates `missed_gainers`, writes `missed_gainer_analyzer` health logs, and does not call CoinDCX APIs, fetch market data, create trades, or place real orders.

## Realtime monitor Supervisor setup

Task 38 adds production-safe Supervisor files for keeping only lightweight candidate/trade monitors alive on a VPS. Full-market scans remain manual or scheduled separately and should not be run continuously by Supervisor. The realtime process does not scan all coins continuously, does not run all-coin candle collection, does not poll all-coin orderbooks, does not run the daily gainer leaderboard, and does not run the missed gainer analyzer.

The combined Python loop is:

```bash
cd python
python scripts/run_realtime_monitors_loop.py --interval 15 --limit 100
```

It runs the existing monitors in this order: trade plan trigger checking, breakout conversion, pullback conversion, active simulated trade price updates, TP/SL event logging, trailing handling, and expiry handling. The process is simulation-only and adds no private CoinDCX APIs, API keys, real trading, or order placement logic.

Local one-cycle test:

```bash
cd python
python scripts/run_realtime_monitors_loop.py --once --interval 5 --limit 50
```

Install and enable Supervisor on a VPS:

```bash
sudo apt update
sudo apt install supervisor -y
sudo cp deploy/supervisor/cryptospot-realtime-monitors.conf.example /etc/supervisor/conf.d/cryptospot-realtime-monitors.conf
sudo nano /etc/supervisor/conf.d/cryptospot-realtime-monitors.conf
sudo mkdir -p /var/log/cryptospot
sudo chown -R www-data:www-data /var/log/cryptospot
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start cryptospot-realtime-monitors
sudo supervisorctl status cryptospot-realtime-monitors
sudo tail -f /var/log/cryptospot/realtime-monitors.log
```

Helper scripts:

```bash
chmod +x deploy/scripts/cryptospot-supervisor-*.sh
deploy/scripts/cryptospot-supervisor-status.sh
deploy/scripts/cryptospot-supervisor-restart.sh
deploy/scripts/cryptospot-supervisor-stop.sh
```

The Supervisor template at `deploy/supervisor/cryptospot-realtime-monitors.conf.example` uses `/var/www/crypto-spot-intraday/python` and `/var/www/crypto-spot-intraday/python/venv/bin/python` as example VPS paths. Adjust those values if your deployment path differs. Do not store secrets or DB credentials in Supervisor config; keep using the project environment files.

## Task 39 Laravel scheduled scan cron

Task 39 locks the MVP into a scheduled/manual scan workflow. The Laravel scheduler runs only one-shot Artisan wrappers around existing Python scripts; the realtime monitor loop remains Supervisor-managed from Task 38 and is not scheduled in Laravel.

### Artisan command wrappers

From the Laravel project root:

```bash
php artisan cryptospot:scan
php artisan cryptospot:daily-gainers
php artisan cryptospot:missed-gainers
php artisan cryptospot:cleanup
```

The commands call these existing Python scripts from the configured Python working directory:

- `cryptospot:scan` calls `scripts/run_manual_scan_once.py` to run one full scan pipeline.
- `cryptospot:daily-gainers` calls `scripts/run_daily_gainer_leaderboard_once.py --quote USDT --limit 100` by default.
- `cryptospot:missed-gainers` calls `scripts/run_missed_gainer_analyzer_once.py --quote USDT --min-change 10 --limit 100` by default.
- `cryptospot:cleanup` calls `scripts/run_data_cleanup_once.py` to run the retention cleanup once.

The scan command runs the existing scan pipeline as a one-shot process. It does not schedule separate all-coin ticker, candle, orderbook, metrics, or scoring jobs.

### Environment values

Local defaults:

```bash
CRYPTOSPOT_PYTHON_BINARY=python
CRYPTOSPOT_PYTHON_DIR=python
CRYPTOSPOT_QUOTE_FILTER=USDT
CRYPTOSPOT_DAILY_GAINER_LIMIT=100
CRYPTOSPOT_MISSED_GAINER_MIN_CHANGE=10
CRYPTOSPOT_MISSED_GAINER_LIMIT=100
```

VPS example:

```bash
APP_ENV=production
APP_DEBUG=false
APP_URL=https://gainforge.in/cryptospot
CRYPTOSPOT_PYTHON_BINARY=/var/www/crypto-spot-intraday/python/venv/bin/python
CRYPTOSPOT_PYTHON_DIR=/var/www/crypto-spot-intraday/python
CRYPTOSPOT_QUOTE_FILTER=USDT
CRYPTOSPOT_DAILY_GAINER_LIMIT=100
CRYPTOSPOT_MISSED_GAINER_MIN_CHANGE=10
CRYPTOSPOT_MISSED_GAINER_LIMIT=100
```

Adjust the `/var/www/crypto-spot-intraday` path if the project is deployed elsewhere.

### Locked Laravel scheduler

The schedule uses the `Asia/Kolkata` timezone and writes scheduler command output to `storage/logs/cryptospot-scheduler.log`.

- Full scan: hourly at minute `00` (`00:00` through `23:00` IST), with a 60-minute overlap lock.
- Daily gainer leaderboard: every 4 hours at minute `15` (`15 */4 * * *`), with a 30-minute overlap lock.
- Missed gainer analyzer: every 4 hours at minute `20` (`20 */4 * * *`), with a 30-minute overlap lock.
- Retention cleanup: daily at `03:30` IST, with a 60-minute overlap lock.

The realtime monitor remains separate and Supervisor-only:

```bash
sudo supervisorctl status cryptospot-realtime-monitors
```

### VPS cron setup

Linux cron should invoke Laravel's scheduler every minute:

```bash
crontab -e
```

Add:

```cron
* * * * * cd /var/www/crypto-spot-intraday && php artisan schedule:run >> /dev/null 2>&1
```

For local Windows development, run individual commands manually or test scheduler dispatch with:

```bash
php artisan schedule:run
```

### Deployment verification

```bash
cd /var/www/crypto-spot-intraday
php artisan optimize:clear
php artisan list | grep cryptospot
php artisan schedule:list
php artisan cryptospot:scan
php artisan cryptospot:daily-gainers
php artisan cryptospot:missed-gainers
php artisan cryptospot:cleanup
tail -f storage/logs/cryptospot-scheduler.log
tail -f storage/logs/laravel.log
```

Useful database checks after manual runs:

```sql
SELECT id, status, started_at, finished_at
FROM scan_runs
ORDER BY id DESC
LIMIT 5;

SELECT leaderboard_date, quote_filter, COUNT(*) cnt
FROM daily_gainer_leaderboard
GROUP BY leaderboard_date, quote_filter
ORDER BY leaderboard_date DESC;

SELECT analysis_date, COUNT(*) cnt
FROM missed_gainers
GROUP BY analysis_date
ORDER BY analysis_date DESC;

SELECT service_name, status, message, checked_at
FROM system_health_logs
WHERE service_name IN (
    'scan_runner',
    'daily_gainer_leaderboard',
    'missed_gainer_analyzer',
    'retention_cleanup'
)
ORDER BY checked_at DESC
LIMIT 20;
```

### Safety notes

Task 39 does not add private CoinDCX API usage, API keys, real trading, order placement, continuous full-market scanning, Laravel-scheduled realtime monitors, or separately scheduled all-coin candle/orderbook/metrics/scoring collectors. It only schedules one-shot scan, daily leaderboard, missed-gainer analyzer, and cleanup wrappers.

## System Health Page

The read-only System Health page is available at `/cryptospot/system-health` for logged-in users. It helps monitor scheduled scans, daily analytics, missed-gainer analysis, retention cleanup, and realtime Supervisor-managed monitor freshness.

The page reads `system_health_logs` plus database freshness timestamps from scan, watchlist, trade-plan, simulated-trade, trade-event, daily-gainer, and missed-gainer tables. It does not start, stop, restart, or execute Python, Supervisor, shell, CoinDCX API, private API, or trading actions.

Useful operator commands to run manually on the server:

```bash
php artisan schedule:list
sudo supervisorctl status cryptospot-realtime-monitors
sudo tail -f /var/log/cryptospot/realtime-monitors.log
tail -f storage/logs/cryptospot-scheduler.log
tail -f storage/logs/laravel.log
```
