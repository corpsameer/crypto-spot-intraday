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

## Pullback simulated entry

The pullback entry simulator converts already-triggered pullback `trade_plans` into active `simulated_trades`. It loads only plans where `status = 'triggered'`, `entry_strategy = 'pullback'`, and `simulated_trade_id IS NULL`, then creates one simulation row per eligible plan.

For each converted plan, the simulator:

- Creates an active long `simulated_trades` row with `source = trade_plan` and `entry_strategy = pullback`.
- Selects the simulated entry price from `latest_price`, then `entry_price`, then `trigger_price`.
- Creates one `ENTRY_TRIGGERED` `trade_events` row.
- Updates the source `trade_plans` row to `status = converted_to_trade` and links `simulated_trade_id`.
- Writes a `pullback_entry_simulator` row to `system_health_logs`.

This step handles pullback entries only. Breakout entries are handled by `BreakoutEntrySimulator`. It does not monitor active trades yet, does not log TP/SL events yet, does not call CoinDCX private APIs, does not use API keys, and does not place real trades.

Run once from the Python folder:

```bash
cd python
python scripts/run_pullback_entry_simulator_once.py --limit 20
```

## Active simulated trade monitor

The active simulated trade monitor is the lightweight runtime monitor for open `simulated_trades` only. It loads non-closed trades with open statuses (`active`, `tp1_hit`, `tp2_hit`, and `trailing_active`), fetches the public CoinDCX ticker once per cycle, matches only those trade symbols, and updates runtime price and P&L fields.

For each matched active trade, it updates:

- `latest_price`
- `highest_price`
- `lowest_price`
- `current_pnl_percent`
- `max_gain_percent`
- `max_drawdown_percent`
- `raw_payload.active_trade_monitor` with the latest monitor snapshot

The P&L calculation is long-only for the spot MVP: `current_pnl_percent = ((latest_price - entry_price) / entry_price) * 100`. Highest/lowest prices are carried forward from prior monitor cycles, while max gain and max drawdown are recalculated from the entry price.

This monitor does **not** create TP/SL events yet, does **not** set TP/SL hit timestamps, does **not** close trades, does **not** implement trailing logic, does **not** place real trades, does **not** use private CoinDCX APIs or API keys, and does **not** scan all coins. Expiry and TP/SL condition flags may be recorded in `raw_payload.active_trade_monitor` as read-only awareness only; later tasks will handle event logging and trade closing.

Run a one-shot check from inside the `python` folder:

```bash
cd python
python scripts/run_active_trade_monitor_once.py --limit 50
```

Run a loop test from inside the `python` folder:

```bash
python scripts/run_active_trade_monitor_loop.py --interval 15 --limit 50
```

The loop uses `monitor.active_trade_refresh_seconds` from `app_settings` when `--interval` is not provided, defaulting to 15 seconds if the setting is missing. The loop monitors only active simulated trades and is not an all-coin scanner. Supervisor setup is intentionally not included in this task.

## TP1/TP2/SL event logging

The trade event monitor is the Task 26 simulation event logger for open `simulated_trades`. It uses `latest_price` that has already been stored by the `ActiveTradeMonitor`; it does not fetch CoinDCX prices itself and does not scan all coins.

For each open long simulated trade, it checks the stored prices and logs idempotent trade events:

- `TP1_HIT` when `latest_price >= tp1_price`.
- `TP2_HIT` when `latest_price >= tp2_price`.
- `SL_HIT` when `latest_price <= sl_price`.

`TP1_HIT` and `TP2_HIT` are milestones only. They update `tp1_hit_at` / `tp2_hit_at` and keep the simulated trade open. `SL_HIT` closes the simulated trade with `status = closed_sl`, `closed_at`, `close_price`, `close_reason = sl`, and `final_pnl_percent`.

The monitor checks for an existing `(simulated_trade_id, event_type)` before inserting, so repeated runs do not duplicate `TP1_HIT`, `TP2_HIT`, or `SL_HIT` events. If a matching event already exists, it repairs the corresponding simulated trade timestamp/status where needed.

Trailing after TP2 is handled by the separate `TrailingMonitor`. Expiry/final close is not implemented here. This monitor is simulation-only: it does not place real trades, does not use private CoinDCX APIs, does not require API keys, and does not add real trading logic.

Recommended one-shot flow from the project root:

```bash
cd python
python scripts/run_active_trade_monitor_once.py --limit 50
python scripts/run_trade_event_monitor_once.py --limit 50
```

Run a loop test from inside the `python` folder:

```bash
python scripts/run_trade_event_monitor_loop.py --interval 15 --limit 50
```

The loop uses `monitor.active_trade_refresh_seconds` from `app_settings` when `--interval` is not provided, defaulting to 15 seconds if the setting is missing. It checks only open simulated trades and should be run after or alongside the active trade price monitor in the MVP flow.

## Trailing after TP2

The trailing monitor activates trailing stops for open simulated trades only after TP2 has been hit. It uses the `latest_price`, `highest_price`, `max_gain_percent`, and `tp2_hit_at` values already stored on `simulated_trades`; it does not fetch market prices, does not scan all coins, and does not place real trades.

After TP2, the monitor:

- Sets `trailing_active = 1`, `status = trailing_active`, and `trailing_started_at` when trailing starts.
- Creates one idempotent `TRAILING_STARTED` event per simulated trade.
- Reads `trailing.levels` from `app_settings` and chooses the highest configured level where `max_gain_percent >= gain_percent`.
- Calculates `current_trailing_sl_price` as `entry_price * (1 + locked_gain_percent / 100)`.
- Updates `current_trailing_sl_price` only when the locked trailing stop improves.
- Creates `TRAILING_UPDATED` events only when the locked gain improves by at least `trailing.min_update_step_percent`.
- Creates one idempotent `TRAILING_STOP_HIT` event when `latest_price <= current_trailing_sl_price`.
- Closes the simulated trade as `closed_trailing` with `close_reason = trailing_stop` and `final_pnl_percent` when the trailing stop is hit.

The default trailing settings are stored in the `trailing` group:

- `trailing.enabled`
- `trailing.activation_after_tp2`
- `trailing.levels`
- `trailing.min_update_step_percent`
- `trailing.close_on_trailing_stop`

Recommended one-shot flow from the project root:

```bash
cd python
python scripts/run_active_trade_monitor_once.py --limit 50
python scripts/run_trade_event_monitor_once.py --limit 50
python scripts/run_trailing_monitor_once.py --limit 50
```

Run a loop test from inside the `python` folder:

```bash
python scripts/run_trailing_monitor_loop.py --interval 15 --limit 50
```

The loop uses `monitor.active_trade_refresh_seconds` from `app_settings` when `--interval` is not provided, defaulting to 15 seconds if the setting is missing. It checks only open simulated trades and should run after the active trade price monitor and TP1/TP2/SL event monitor in the MVP flow. Supervisor setup is intentionally not included in this task.

## Scan-cycle opportunity expiry

Simulated trades no longer expire by time or by scan cycle. The legacy `trade_expiry_monitor` is disabled and does not update `simulated_trades`, create expiry events, set `close_reason = expiry`, or close active trades. Open simulated trades close only through SL, TP/trailing, or a future manual-close workflow.

Opportunity expiry now happens in `scan_cycle_expiry_manager` immediately after a new `scan_runs` row is created for a full scan and before the new scan writes watchlist candidates or trade plans. It expires only older unexecuted `candidate_watchlists` and older pending/watching unconverted `trade_plans` with reason `new_scan_replaced`; `simulated_trades_expired` is always `0`. Triggered plans are deliberately skipped to avoid a race with breakout/pullback entry conversion. Reserved capital on expired untriggered plans is not released here and is deferred to a later portfolio release task.

Recommended realtime flow from the project root remains active-trade monitoring, TP/SL event monitoring, and trailing handling; there is no realtime simulated-trade expiry close step.

## Daily gainer leaderboard

The daily gainer leaderboard is a one-shot utility for capturing the actual CoinDCX spot top gainers for a date. It fetches the CoinDCX ticker endpoint once per run, matches rows to active `spot_symbols`, applies a quote filter such as `USDT`, ranks symbols by 24h percentage change, and refreshes rows in `daily_gainer_leaderboard` for that date/quote/source.

This command does **not** fetch candles, fetch orderbooks, poll liquidity, scan continuously, create watchlist candidates, create trade plans, create simulated trades, create trade events, call private CoinDCX APIs, require API keys, or place real trades. The stored rows are intended for later missed-gainer comparison in a future analyzer task.

Run from the Python folder:

```bash
cd python
python scripts/run_daily_gainer_leaderboard_once.py --quote USDT --limit 100
```

Optional arguments:

```bash
python scripts/run_daily_gainer_leaderboard_once.py --date 2026-06-17 --quote USDT --limit 100
python scripts/run_daily_gainer_leaderboard_once.py --quote ALL --limit 100
```

The script writes a `daily_gainer_leaderboard` entry to `system_health_logs` with the run summary.

## Missed gainer analyzer

The missed gainer analyzer is a one-shot review utility that uses stored `daily_gainer_leaderboard` rows and compares the actual top gainers against existing `scan_results`, `candidate_watchlists`, `trade_plans`, `simulated_trades`, and `trade_events`.

It populates `missed_gainers` with a per-symbol classification such as `missed_completely`, `captured_not_selected`, `selected_no_trade_plan`, `trade_plan_not_triggered`, or `captured_trade_created`. The analyzer is idempotent for each `analysis_date` + `coindcx_symbol` pair and refreshes existing analysis rows on re-run.

This utility only reads existing scanner/simulation data and writes analysis rows plus a `missed_gainer_analyzer` health log entry. It does **not** call the CoinDCX API, fetch tickers, fetch candles, fetch orderbook/liquidity data, place trades, create simulated trades, or create trade events.

Run it from the Python folder:

```bash
cd python
python scripts/run_missed_gainer_analyzer_once.py --quote USDT --min-change 10 --limit 100
```

Optional date-specific run:

```bash
python scripts/run_missed_gainer_analyzer_once.py --date 2026-06-17 --quote USDT --min-change 10 --limit 100
```

## Realtime monitor Supervisor loop

Task 38 adds a single lightweight realtime monitor loop for VPS Supervisor. It keeps only candidate/trade monitoring processes alive between scheduled or manual full-market scans. Full scans remain manual or scheduled separately; this loop does not run the scan runner, does not continuously collect all-coin candles, does not poll all-coin orderbooks, does not build the daily gainer leaderboard, and does not run the missed gainer analyzer.

The combined process runs these existing simulation monitors in order each cycle:

1. `TradePlanTriggerMonitor` checks only pending/watching `trade_plans`.
2. `BreakoutEntrySimulator` converts triggered breakout plans to simulated trades.
3. `PullbackEntrySimulator` converts triggered pullback plans to simulated trades.
4. `ActiveTradeMonitor` updates only open `simulated_trades`.
5. `TradeEventMonitor` logs TP1/TP2/SL events from stored latest prices.
6. `TrailingMonitor` handles trailing behavior after TP2.
Scan-cycle opportunity expiry is handled by `ScanCycleExpiryManager` during full scans; no realtime monitor closes simulated trades for expiry.

Run one local acceptance cycle from inside the `python` folder:

```bash
python scripts/run_realtime_monitors_loop.py --once --interval 5 --limit 50
```

Run a short local loop test, then stop with `Ctrl+C`:

```bash
python scripts/run_realtime_monitors_loop.py --interval 10 --limit 50
```

The script supports optional skip flags for operational testing: `--skip-plan-trigger`, `--skip-entry-simulators`, `--skip-active-trade`, `--skip-events`, and `--skip-trailing`. If `--interval` is not supplied, it reads `monitor.active_trade_refresh_seconds` and falls back to 15 seconds.

Each monitor failure is caught and printed as a compact JSON error so the next monitor can continue. Startup/import failures exit with code `1`; normal completion, `--once`, and `Ctrl+C` exit with code `0`.

### VPS Supervisor setup

The Supervisor template is located at:

```text
deploy/supervisor/cryptospot-realtime-monitors.conf.example
```

It is an example only. Adjust the project path, virtualenv path, and `user` for your VPS if they differ. Do not put secrets, DB credentials, CoinDCX API keys, or private API settings in the Supervisor file; Python should continue reading project `.env` files as already implemented.

Install and enable Supervisor on Ubuntu/Debian VPS:

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

Restart or stop later with:

```bash
sudo supervisorctl restart cryptospot-realtime-monitors
sudo supervisorctl stop cryptospot-realtime-monitors
```

Optional helper scripts are available from the project root:

```bash
chmod +x deploy/scripts/cryptospot-supervisor-*.sh
deploy/scripts/cryptospot-supervisor-status.sh
deploy/scripts/cryptospot-supervisor-restart.sh
deploy/scripts/cryptospot-supervisor-stop.sh
```

### Health-log verification

After the loop runs, verify monitor health rows in MySQL:

```sql
SELECT service_name, status, message, checked_at
FROM system_health_logs
WHERE service_name IN (
    'trade_plan_trigger_monitor',
    'breakout_entry_simulator',
    'pullback_entry_simulator',
    'active_trade_monitor',
    'trade_event_monitor',
    'trailing_monitor',
    'scan_cycle_expiry_manager'
)
ORDER BY checked_at DESC
LIMIT 30;
```

Safety reminders: the Supervisor process is simulation-only. It does not place real orders, does not use CoinDCX private APIs, does not need API keys, and does not add real trading or order placement logic.

## Portfolio gate

Trade plan generation now runs a portfolio gate before creating active `pending` or `watching` trade plans. The gate is applied after scan results are selected for the watchlist and before each candidate plan is persisted, so the scan flow remains `scan_run -> scan_results -> candidate_watchlist -> trade_plan -> simulated_trade -> trade_events -> analytics` while preventing unrealistic duplicate opportunities.

The gate checks whether portfolio simulation is enabled, whether an active portfolio account exists, current open simulated trades, pending trade plans, total open opportunities, duplicate symbols, symbol cooldown after recently closed trades, and available cash against the configured minimum trade capital. Candidates that fail the gate are stored as trade plans with `status = portfolio_rejected`, `portfolio_status = rejected`, and `portfolio_rejection_reason` for analytics; accepted candidates use `portfolio_status = approved` and continue through the normal pending trade-plan workflow.

Task 45 is gate-only. It does **not** allocate capital, reserve cash, deduct cash, calculate quantity, place real orders, call private CoinDCX APIs, or add API keys. Capital allocation and reservation are intentionally deferred to Task 46.

## Capital allocation to trade plans

Approved portfolio-gated trade plans now receive an `allocated_capital` amount when the trade plan is created. Allocation is based on the selected candidate's score, `score_label`, and selection reason: fallback selections use the fallback bucket, strong scores use the strong bucket, watchlist-level scores use the watchlist bucket, and the remainder use the weak bucket. Each requested allocation is capped by the portfolio allocation settings and by tradable cash after the configured reserve-cash percentage is protected.

Capital reservation happens at trade-plan creation time when `portfolio.reserve_capital_on_plan_creation` is enabled. The accounting convention is:

- `current_cash` remains the gross simulated cash balance and is not reduced by reservation.
- `reserved_cash` tracks cash committed to pending trade plans.
- `available_cash` is calculated as `current_cash - reserved_cash`.
- `deployed_capital` changes later when a plan enters a simulated trade.
- `total_equity` is unchanged by reservation.

A `capital_reserved` portfolio transaction is written once per reserved trade plan. The reservation path is idempotent: rerunning scans will not double-count an already reserved trade plan or create duplicate `capital_reserved` transactions for the same plan.

Rejected gate candidates and allocation-rejected candidates stay unallocated. Allocation rejection moves the plan to `status = portfolio_rejected` with `portfolio_status = rejected` and a `portfolio_rejection_reason`, so realtime monitors do not treat the rejected plan as usable.

Task 46 remains simulation-only. It does not create simulated trade quantity, carry capital into `simulated_trades`, calculate INR P&L, release capital on expiry or close, change close logic, call CoinDCX private APIs, use API keys, or place real orders. Capital carry-forward into simulated trades is intentionally deferred to Task 47, and capital release is handled by later tasks.

## Carrying allocation into simulated trades

Task 47 carries portfolio allocation from a reserved trade plan into the simulated trade at entry time. When a `trade_plans` row with `portfolio_status = capital_reserved` triggers and is converted by the breakout or pullback entry simulator, the resulting `simulated_trades` row receives the portfolio account, allocated capital, allocation percent, simulated quantity, entry value, current value, initial zero unrealized INR P&L, paper fees, and initial net INR P&L fields.

At entry, the accounting move is from reserved capital to deployed capital only: `reserved_cash` decreases by the allocated amount and `deployed_capital` increases by the same amount. `current_cash` and `total_equity` remain unchanged at entry. A `portfolio_transactions` row with `transaction_type = trade_entry` records this reserved-to-deployed movement and links back to both the trade plan and simulated trade.

The entry simulators remain simulation-only and idempotent. Legacy unallocated trade plans may still convert without portfolio fields, while capital-reserved plans require a matching portfolio account and enough reserved cash before a portfolio-aware simulated trade is created. Active INR P&L updates are intentionally deferred to Task 48, and capital release on close is intentionally deferred to Task 49.

### Active INR P&L monitoring

Task 48 adds active INR P&L monitoring for portfolio-aware simulated trades. During each active-trade monitor cycle, open simulated trades with `portfolio_account_id`, `allocated_capital`, and `quantity` now refresh their INR fields from the latest matched public ticker price:

- `current_value = quantity * latest_price`
- `unrealized_pnl_amount = current_value - allocated_capital`
- `net_pnl_amount = unrealized_pnl_amount - fees_amount`

Legacy simulated trades without portfolio allocation fields continue through the existing percentage-based monitor path and are skipped for INR updates rather than backfilled.

After portfolio trade P&L is updated, affected portfolio accounts are reconciled from their open portfolio trades. `deployed_capital` is the sum of open trade `allocated_capital`, `unrealized_pnl` is the sum of open trade `unrealized_pnl_amount`, and `total_equity = current_cash + unrealized_pnl`. This intentionally does not add deployed capital into equity because `current_cash` is not reduced when capital is reserved or deployed in the paper-accounting model.

Active monitoring does not change `current_cash`, `reserved_cash`, or `realized_pnl` while a trade remains open. It also does not release capital, close trades, write `trade_exit` transactions, or create a portfolio transaction for every unrealized P&L update. Capital release and realized P&L remain deferred to Task 49.
