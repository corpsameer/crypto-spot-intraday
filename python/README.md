# Crypto Spot Intraday Python Tools

This folder contains the Python foundation for the Crypto Spot Intraday Gainer Scanner. It is prepared for future continuous market data collection, metrics, scoring, and simulated trade monitoring, but this task only includes configuration, logging, MySQL helpers, settings reads, health log writes, and a public CoinDCX market data client.

The Python project currently uses only CoinDCX public APIs. No real trading, private API keys, authenticated endpoints, or order placement are implemented.

## Setup

From the project root:

```bash
cd python
python -m venv venv
```

Activate the virtual environment.

Windows Git Bash:

```bash
source venv/Scripts/activate
```

Linux:

```bash
source venv/bin/activate
```

Install dependencies:

```bash
pip install -r requirements.txt
```

Create your local environment file:

```bash
cp .env.example .env
```

Update `python/.env` with your local MySQL credentials if needed.

## Run Tests

Run these commands from inside the `python` folder:

```bash
python main.py
python scripts/test_db_connection.py
python scripts/test_settings_reader.py
python scripts/test_health_log.py
python scripts/test_coindcx_client.py
```

Expected behavior:

- `python main.py` prints the tools message and creates `logs/cryptospot.log`.
- The DB test prints the `spot_symbols` count and writes a health log.
- The settings reader prints seeded Laravel settings and converted Python types.
- The health log test inserts a row into `system_health_logs`.
- The CoinDCX client test fetches public markets and ticker data, then attempts an orderbook request for one active DB symbol if available.


## Run One-Shot Ticker Snapshot

Run this command from inside the `python` folder after activating the virtual environment:

```bash
python scripts/run_ticker_snapshot_once.py
```

This one-shot collector fetches the latest CoinDCX public ticker values and stores matched active spot symbols in `scanner_metrics`. It also stores BTC/ETH market context in `market_snapshots` and writes a `ticker_snapshot_collector` health log entry.

This is not the continuous monitor yet. It does not place trades, use private CoinDCX APIs, require API keys, create candidates, score symbols, or create simulated trades.

## Run One-Shot Candle Collection

Run this command from inside the `python` folder after activating the virtual environment:

```bash
python scripts/run_candle_collection_once.py --limit 10
```

Run selected timeframes only:

```bash
python scripts/run_candle_collection_once.py --limit 5 --timeframes 5m,15m,1h
```

Run only BTC and ETH candles for market context:

```bash
python scripts/run_candle_collection_once.py --base-assets BTC,ETH --timeframes 1m,5m,15m,1h
```

This one-shot collector fetches recent CoinDCX public candle data for active spot symbols and stores it in the `candles` table. It writes a `candle_collector` health log entry and uses public market data only.

This is not the continuous monitor yet. It does not calculate metrics, score symbols, create candidates, create simulated trades, place trades, use private CoinDCX APIs, or require API keys.


## Run One-Shot Orderbook Liquidity Collection

Run this command from inside the `python` folder after activating the virtual environment:

```bash
python scripts/run_orderbook_collection_once.py --quote USDT --limit 10
```

Run with a custom slippage target in the pair quote currency:

```bash
python scripts/run_orderbook_collection_once.py --quote USDT --limit 10 --target-notional 100
```

This one-shot collector fetches CoinDCX public orderbook data using `spot_symbols.api_pair`, for example `B-BTC_USDT`. It stores best bid, best ask, spread percent, orderbook depth, and a simple market-buy slippage estimate in `scanner_metrics`.

Depth is stored in the existing `orderbook_depth_usdt` column, but for now the value is in the pair quote currency, such as USDT or INR depending on the symbol quote asset.

This is not the continuous monitor yet. It does not calculate the full scanner score, create candidates, create simulated trades, place trades, use private CoinDCX APIs, or require API keys.

## Safety Notes

- CoinDCX integration is public-only.
- No CoinDCX API keys are used or expected.
- No private/authenticated CoinDCX endpoints are implemented.
- No buy/sell logic, real trading execution, continuous monitor, scoring, candidate creation, or trade simulation logic is implemented in this foundation.

## CoinDCX API pair handling

The Python collectors use two CoinDCX identifiers from `spot_symbols`:

- `coindcx_symbol`: normalized scanner/display symbol, for example `BTCUSDT`. Ticker matching continues to use this value.
- `api_pair`: raw CoinDCX market-data pair, for example `B-BTC_USDT`. Candle and orderbook market-data requests must use this value.

Configure the public endpoints with:

```env
COINDCX_API_BASE_URL=https://api.coindcx.com
COINDCX_MARKET_DATA_BASE_URL=https://public.coindcx.com
```

`COINDCX_PUBLIC_BASE_URL` remains a backward-compatible fallback for the API base URL only.

After migrating and syncing the Laravel app, validate market pair resolution from the Python directory:

```bash
python scripts/test_market_pair_resolution.py
python scripts/run_candle_collection_once.py --limit 3 --timeframes 1m
```

Validate orderbook liquidity collection from the Python directory:

```bash
python scripts/run_orderbook_collection_once.py --quote USDT --limit 3 --target-notional 100
```

## Run One-Shot Data Cleanup

Start with a dry run from inside the `python` folder after activating the virtual environment:

```bash
python scripts/run_data_cleanup_once.py --dry-run
```

Run actual cleanup:

```bash
python scripts/run_data_cleanup_once.py
```

The cleanup deletes old rows from `candles`, `scanner_metrics`, `market_snapshots`, and `system_health_logs` based on the `retention.*` values in `app_settings`. Candle cleanup is timeframe-specific, so each configured timeframe has its own retention window.

The cleanup never deletes `spot_symbols`, `candidate_watchlists`, `simulated_trades`, `trade_events`, or `missed_gainers`. It writes a `data_cleanup` entry to `system_health_logs` with the cleanup summary.

Use `--dry-run` first to count rows that would be deleted without changing the database, then run without `--dry-run` when you are ready to remove old time-series rows.

## Run One-Shot Metrics Engine

Run this command from inside the `python` folder after activating the virtual environment:

```bash
python scripts/run_metrics_once.py --quote USDT --limit 10
```

This one-shot metrics engine calculates candle-based scanner metrics for active spot symbols and inserts fresh rows into `scanner_metrics`. It uses recent `candles`, merges the latest ticker snapshot metrics, merges latest orderbook/liquidity metrics, and includes the latest BTC/ETH market context from `market_snapshots` when available.

Calculated metrics include short-term price changes, volume spikes, distance from the 24h high, candle close strength, upper/lower wick percentages, relative strength versus BTC, and a simple placeholder overextension risk.

This prepares data for a future scoring engine only. It does not calculate `final_score`, create candidates, create simulated trades, place trades, use private CoinDCX APIs, or require API keys.

## Run One-Shot Market Context Engine

Run this command from inside the `python` folder after activating the virtual environment:

```bash
python scripts/run_market_context_once.py
```

Recommended before running market context, collect recent BTC/ETH candles for the required timeframes:

```bash
python scripts/run_candle_collection_once.py --base-assets BTC,ETH --timeframes 1m,5m,15m,1h
```

This one-shot market context engine resolves active BTC and ETH spot symbols from `spot_symbols`, preferring USDT pairs and falling back to INR pairs. It calculates BTC/ETH 5m, 15m, 1h, 4h, and 24h context from existing `candles` table data, classifies the broad market as `bullish`, `neutral`, `bearish`, `volatile`, or `unknown`, and inserts a fresh row into `market_snapshots`.

It writes a `market_context_engine` health log entry to `system_health_logs` and prints a readable summary containing the resolved symbols, latest prices, market condition, insert status, and warnings/errors.

This task prepares broad-market context only. It does not score symbols, calculate `final_score`, create candidates, create simulated trades, monitor trades, place trades, use private CoinDCX APIs, or require API keys.

## Scheduled Scan Settings

Full-market scans are scheduled/manual only for the MVP. They are not intended to run as a continuous all-coin scanner. The suggested default scan times are `09:00`, `14:00`, `19:00`, and `22:30` IST.

The Laravel `app_settings` table stores the scan workflow configuration:

- `scan.scheduled_times` stores the daily scheduled scan times as JSON, for example `["09:00","14:00","19:00","22:30"]`.
- `scan.timezone` stores the timezone used for those scheduled times. The default is `Asia/Kolkata`.
- `scan.default_quote_filter` controls the default quote asset for a scan, such as `USDT`, `INR`, or `ALL`.
- `prefilter.*` settings control which ticker symbols are allowed to proceed to the future candle, metrics, and scoring stages.
- `monitor.*` settings are for lightweight candidate, pending trade-plan, active simulated-trade, and system-health monitoring only.

Continuous all-coin candle fetching, all-coin scoring, and all-coin orderbook polling are not part of the MVP. This settings test does not run scans, fetch tickers/candles/orderbooks, score symbols, create candidates, create trade plans, place trades, or use private CoinDCX APIs.

Seed the default settings from the Laravel project root:

```bash
php artisan db:seed --class=AppSettingSeeder
```

Verify the Python settings reader from inside the `python` folder:

```bash
python scripts/test_scan_settings.py
```

## Run Manual Scan With Prefilter

Run this command from inside the `python` folder after activating the virtual environment:

```bash
python scripts/run_manual_scan_once.py --name "Manual Prefilter Test" --quote USDT --limit 100
```

The manual scan runner now performs the scheduled/manual MVP scan flow through the ticker-level prefilter stage:

- It creates a `scan_runs` row with `scan_type = manual` and `status = running`, then marks it `completed` or `failed`.
- It respects `scan.enabled`, `scan.allow_manual_scan`, `scan.prevent_overlap`, and `scan.default_quote_filter` settings from `app_settings`.
- It prevents overlapping manual scans when `scan.prevent_overlap` is enabled and a `scan_runs.status = running` row already exists.
- It loads active rows from `spot_symbols`, applies the quote filter and optional CLI limit, and matches tickers using normalized `coindcx_symbol` values.
- It fetches CoinDCX ticker data once for the scan through the public `CoinDCXPublicClient.ticker()` endpoint.
- It inserts `scan_results` rows for matched symbols with `status = discovered` and `stage = ticker`, then applies ticker-level prefilter rules in the same scan.
- The prefilter uses the `app_settings` group `prefilter` plus `scan.max_prefilter_symbols` to update rows to `prefilter_passed` or `prefilter_rejected`.
- Only `prefilter_passed` `scan_results` rows proceed to the optional scan-based candle fetching step when `scan.fetch_candles_for_candidates` is enabled.
- It updates `scan_runs.prefilter_passed_count`, stores scan summaries in `scan_runs.raw_payload`, and writes `scan_runner`, `prefilter_engine`, and optional `scan_candle_collector` entries to `system_health_logs`.

This task still does not calculate metrics, score symbols, create watchlist candidates, create trade plans, create simulated trades, or run continuously. It also does not place trades, use private CoinDCX APIs, or require API keys.

## Run Manual Scan With Prefilter and Scan-Based Candles

Run this command from inside the `python` folder after activating the virtual environment:

```bash
python scripts/run_manual_scan_once.py --name "Scan With Candles" --quote USDT --limit 100
```

Optionally restrict scan-candidate candle timeframes for a manual test:

```bash
python scripts/run_manual_scan_once.py --name "Scan With Candles" --quote USDT --limit 100 --timeframes 1m,5m,15m
```

When `scan.fetch_candles_for_candidates` is enabled in `app_settings`, the scan runner fetches candles only for `scan_results` rows from the current `scan_run` where `prefilter_passed = 1`. It uses the scan result `api_pair` first and falls back only to `spot_symbols.api_pair`; it does not fetch candles for all active symbols as part of the MVP scan workflow.

The candle step updates successful prefilter-passed rows from `prefilter_passed` to `status = candles_fetched` and `stage = candles`. Rows that cannot be fetched are marked `status = failed`, `stage = candles`, with `rejection_reason` such as `missing_api_pair` or `candle_fetch_failed`. Prefilter-rejected rows are not attempted for candle fetching.

The scan runner also updates `scan_runs.candles_fetched_count` to the number of scan results that successfully fetched and stored candles, and writes a `scan_candle_collector` health log.

This scan-based candle step still does not calculate scan metrics, calculate `final_score`, score candidates, create `candidate_watchlists`, create `trade_plans`, create `simulated_trades`, place trades, use private CoinDCX APIs, or require API keys.

The older `scripts/run_candle_collection_once.py` script remains available only for manual debugging or backfill. Do not run it continuously for all coins, and do not use it as an all-market scanner in the MVP workflow.

## Manual scan with scan-based metrics

Run a scheduled/manual MVP scan with prefiltering, scan-based candle collection, and scan-based metrics:

```bash
python scripts/run_manual_scan_once.py --name "Scan Metrics Test" --quote USDT --limit 50
```

The scan runner now calculates metrics only for `scan_results` rows in the current `scan_run` that passed the prefilter and successfully reached `status = candles_fetched`. It does not calculate metrics for all active coins during the MVP scan workflow.

For each eligible scan result, the metrics engine reads existing `candles` rows, merges the latest BTC/ETH market context from `market_snapshots`, optionally merges the latest existing orderbook/liquidity values from `scanner_metrics`, inserts a fresh `scanner_metrics` row with `raw_payload.source = scan_based_metrics`, and links it back through `scan_results.scanner_metric_id`.

This scan-based metrics step still does not calculate `final_score`, does not create watchlist candidates, does not create trade plans, does not create simulated trades, does not place trades, and does not use private CoinDCX APIs or API keys.

## Manual scan with per-scan market context and candidate liquidity

Run a scheduled/manual MVP scan with fresh BTC/ETH market context, prefiltering, candidate-only orderbook liquidity, scan-based candles, and scan-based metrics:

```bash
python scripts/run_manual_scan_once.py --name "Scan Context Liquidity Test" --quote USDT --limit 50
```

Each manual scan now runs `MarketContextEngine` once for the new `scan_runs` row and stores the returned market context summary, including `market_snapshot_id`, in `scan_runs.raw_payload`. The market snapshot row also records scan metadata in its `raw_payload` when generated by the scan runner.

When `scan.fetch_orderbook_for_candidates` is enabled, the scan refreshes orderbook/liquidity only for prefilter-passed scan candidates from the current `scan_run`. It does not fetch orderbooks for all active coins and does not run an all-market liquidity poller.

The candidate liquidity refresh enriches the matching `scan_results` rows with fresh `spread_percent`, `orderbook_depth_usdt`, and `slippage_estimate_percent` values. It also creates liquidity-only `scanner_metrics` rows with `raw_payload.source = scan_liquidity_refresh`; these rows are intentionally not linked through `scan_results.scanner_metric_id`, because that link remains reserved for the full scan-based metrics row.

The scan-based metrics step prefers scan-fresh liquidity from `scan_results` and the same-scan liquidity metric rows where available. If scan-fresh liquidity is missing, it falls back to the latest existing orderbook metrics and then ticker spread behavior.

This Task 11.1 workflow still does not calculate `final_score` unless a later scoring task already exists and is already wired into the scan runner. It does not create watchlist candidates, create trade plans, create simulated trades, place trades, use private CoinDCX APIs, require API keys, or run continuously.

## Manual scan with prefilter, candidate liquidity, candles, metrics, and scoring

Run a scheduled/manual MVP scan with prefiltering, candidate-only orderbook liquidity, scan-based candle collection, scan-based metrics, and scan-based scoring:

```bash
python scripts/run_manual_scan_once.py --name "Scan Scoring Test" --quote USDT --limit 50
```

The scan runner now scores only `scan_results` rows in the current `scan_run` that reached `status = metrics_calculated` and have a linked `scanner_metric_id`. It does not score every active coin outside the manual/scheduled scan workflow.

The scan-based scoring step uses stored momentum, volume spike, breakout/near-high, overextension risk, scan-fresh liquidity/spread, relative strength versus BTC, and per-scan market context metrics. It reads per-scan market context from `scan_runs.raw_payload.market_context.market_condition` when available, then falls back to the latest `market_snapshots` row, and never calls CoinDCX directly.

For each scored row, the scoring engine updates `scan_results.final_score`, `scan_results.score_label`, `scan_results.score_breakdown`, `scan_results.risk_penalty`, and `scan_results.score_passed`. It also updates the linked `scanner_metrics` row with `final_score`, `score_label`, `risk_penalty`, `passes_watchlist`, and `passes_strong`.

The scoring summary updates `scan_runs.scored_count`, `scan_runs.top_score`, and `scan_runs.top_symbol`, and writes a `scan_scoring_engine` health log entry.

This Task 12 workflow still does not create watchlist candidates, does not create trade plans, does not create simulated trades, does not place trades, does not use private CoinDCX APIs, does not require API keys, and does not run continuously.

## Top-N fallback candidate selection

Neutral or weak market scans can legitimately produce zero rows above the configured watchlist score threshold. Task 12.1 adds a scan-scoped top-N fallback selection step so the system can still mark the best available scored rows for later candidate creation without pretending they are high-confidence signals.

After scoring, the scanner marks rows in `scan_results` using these fields:

- `selected_for_watchlist`: `1` when a scored row should be carried forward by the next candidate-creation task.
- `selection_type`: `threshold`, `fallback`, or `none`.
- `selection_rank`: rank among selected rows, ordered by `final_score` descending.
- `selection_reason`: human-readable explanation for threshold or fallback selection.

Selection rules:

1. Rows with `final_score >= scanner.watchlist_score_threshold` are selected as `selection_type = threshold`.
2. If fewer than `scanner.min_required_candidates` pass the threshold and `scanner.enable_fallback_candidates` is enabled, the scanner adds the top scored rows with `final_score >= scanner.min_fallback_candidate_score`.
3. Fallback rows keep their normal score label. For example, a fallback row below the watchlist threshold remains `score_label = weak` while `selection_type = fallback` and `selected_for_watchlist = 1`.
4. Selection never exceeds `scan.max_final_candidates`.
5. Candidate creation in the next task should read `selected_for_watchlist = 1` from `scan_results`.

This step does **not** create `candidate_watchlists` rows, `trade_plans`, simulated trades, private CoinDCX API calls, API keys, or real trading logic.

Run the migration and refresh settings before testing:

```bash
php artisan migrate
php artisan db:seed --class=AppSettingSeeder
```

Then run a manual scan:

```bash
cd python
python scripts/run_manual_scan_once.py --name "Fallback Selection Test" --quote USDT
```

## Watchlist candidate creation from scan results

After scan-based scoring and top-N fallback selection, the scan runner now converts selected `scan_results` rows into active `candidate_watchlists` rows. The source is strictly `selected_for_watchlist = 1`, so both threshold-selected rows and fallback-selected rows can become watchlist candidates.

The watchlist candidate step keeps one active candidate per symbol. When a selected scan result points to a symbol that already has an active/refreshed `candidate_watchlists` row, that row is refreshed with the latest scan score, latest scan metadata, and capped raw-payload history instead of creating a duplicate active candidate.

The candidate creation step links each processed `scan_results` row back to the watchlist row through `candidate_watchlist_id`, marks `candidate_created = 1`, stores the watchlist summary in `scan_runs.raw_payload.watchlist`, and sets `scan_runs.watchlist_created_count` to the number of selected scan rows linked in that scan.

This task does **not** create trade plans, does **not** create simulated trades, does **not** place trades, does **not** use private CoinDCX APIs, and does **not** require API keys.

Run a manual scan with watchlist candidate creation from the project root:

```bash
cd python
python scripts/run_manual_scan_once.py --name "Watchlist Creation Test" --quote USDT
```

## Trade plan generation from watchlist candidates

After scan-based watchlist creation, selected `scan_results` rows linked to active or refreshed `candidate_watchlists` are converted into pending `trade_plans`. The generator is intentionally scan-based: it runs after watchlist creation during a manual or scheduled scan and does not continuously scan all markets.

The trade plan generator creates a breakout plan for each eligible selected candidate. It may also create a pullback plan when the candidate appears extended, such as when short-term momentum is strong, the price is near its 24h high, or overextension risk is elevated. Each pending plan includes a trigger price, entry price, TP1, TP2, SL, trailing start price, validity window, score context, and raw scan payload history.

No simulated trade is created by this step. A later trade plan trigger monitor will be responsible for watching pending plans and creating simulated trades only when an entry condition is met. This project step does not place real trades, does not use private CoinDCX APIs, and does not require API keys.

Run a manual scan with trade plan generation:

```bash
cd python
python scripts/run_manual_scan_once.py --name "Trade Plan Generator Test" --quote USDT
```

## Task 19.1 fixes

Task 19.1 keeps the MVP workflow scan-based and fixes two scan-run visibility and freshness issues:

- Manual scan CLI output now includes the `trade_plans` summary returned by the scan runner.
- `scan_runs.raw_payload` includes the `trade_plans` summary, and `scan_runs.trade_plans_created_count` is based on the number of selected scan results linked to a primary trade plan rather than the total number of breakout plus pullback rows.
- Market context refreshes fresh BTC/ETH candles immediately before calculating and inserting each scan market snapshot.
- BTC/ETH context refresh is intentionally limited to the resolved BTC and ETH context symbols only (`BTCUSDT`/`ETHUSDT`, with `BTCINR`/`ETHINR` fallback when USDT is unavailable); it does not fetch candles for all coins.
- No real trading, private CoinDCX API usage, API keys, trade-trigger monitoring, simulated trade creation, active trade monitoring, TP/SL event logging, or continuous all-market scanning is added by this fix.

## Trade plan trigger monitor

The trade plan trigger monitor is the lightweight Task 22 monitor for pending entry plans. It monitors only `trade_plans` rows whose status is `pending` or `watching`; it is not an all-coin scanner and it does not fetch candles or orderbooks.

On each cycle, it fetches the CoinDCX public ticker once, matches current prices only for symbols that have active trade plans, and updates runtime tracking fields on those plans:

- `latest_price`
- `highest_price_seen`
- `lowest_price_seen`
- `max_plan_gain_percent`
- `max_plan_drawdown_percent`

Breakout plans are marked `triggered` when `latest_price >= trigger_price`. Pullback plans are marked `triggered` when `latest_price <= trigger_price`. Plans past `expires_at` are marked `expired` before trigger checks. Pending plans that are neither expired nor triggered become `watching`.

This monitor does **not** create simulated trades yet, does **not** create trade events, does **not** place real trades, does **not** use private CoinDCX APIs or API keys, and does **not** scan all coins. Later tasks will convert triggered breakout and pullback plans into simulated trades.

Run a one-shot check from inside the `python` folder:

```bash
python scripts/run_trade_plan_trigger_monitor_once.py --limit 50
```

Run a loop test from inside the `python` folder:

```bash
python scripts/run_trade_plan_trigger_monitor_loop.py --interval 30 --limit 50
```

The loop uses `monitor.trade_plan_refresh_seconds` from `app_settings` when `--interval` is not provided, defaulting to 30 seconds if the setting is missing. Supervisor setup is intentionally not included in this task.

## Breakout simulated entry

The breakout entry simulator converts already-triggered breakout `trade_plans` into active `simulated_trades`. It loads only plans where `status = 'triggered'`, `entry_strategy = 'breakout'`, and `simulated_trade_id IS NULL`, then creates one simulation row per eligible plan.

For each converted plan, the simulator:

- Creates an active long `simulated_trades` row with `source = trade_plan` and `entry_strategy = breakout`.
- Selects the simulated entry price from `latest_price`, then `entry_price`, then `trigger_price`.
- Creates one `ENTRY_TRIGGERED` `trade_events` row.
- Updates the source `trade_plans` row to `status = converted_to_trade` and links `simulated_trade_id`.
- Writes a `breakout_entry_simulator` row to `system_health_logs`.

This step is simulation-only. It does not handle pullback entries yet, does not monitor active trades, does not log TP/SL events, does not call CoinDCX private APIs, does not use API keys, and does not place real trades.

Run once from the Python folder:

```bash
cd python
python scripts/run_breakout_entry_simulator_once.py --limit 20
```
