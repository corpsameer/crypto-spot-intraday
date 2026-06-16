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

