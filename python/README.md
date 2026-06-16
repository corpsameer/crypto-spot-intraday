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
