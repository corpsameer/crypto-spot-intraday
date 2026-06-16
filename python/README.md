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

## Safety Notes

- CoinDCX integration is public-only.
- No CoinDCX API keys are used or expected.
- No private/authenticated CoinDCX endpoints are implemented.
- No buy/sell logic, real trading execution, continuous monitor, scoring, candidate creation, or trade simulation logic is implemented in this foundation.
