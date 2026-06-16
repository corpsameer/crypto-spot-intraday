import json
import re
import time
from datetime import datetime, timedelta
from typing import Any

from cryptospot.coindcx_client import CoinDCXPublicClient
from cryptospot.db import execute_many, fetch_all
from cryptospot.health import write_health_log
from cryptospot.logger import get_logger

SERVICE_NAME = "candle_collector"
DEFAULT_TIMEFRAMES = ["1m", "5m", "15m", "1h", "4h"]
INTERVAL_MAP = {
    "1m": "1m",
    "5m": "5m",
    "15m": "15m",
    "1h": "1h",
    "4h": "4h",
}
LOOKBACK_WINDOWS = {
    "1m": timedelta(minutes=180),
    "5m": timedelta(hours=24),
    "15m": timedelta(hours=48),
    "1h": timedelta(days=7),
    "4h": timedelta(days=14),
}
API_SLEEP_SECONDS = 0.15
PAIR_LIKE_PATTERN = re.compile(r"^[A-Z0-9]+-[A-Z0-9]+_[A-Z0-9]+$")

logger = get_logger(__name__)


def safe_float(value: Any, default=None):
    if value is None or value == "":
        return default

    try:
        return float(str(value).replace(",", "").strip())
    except (TypeError, ValueError):
        return default


def safe_int(value: Any, default=None):
    if value is None or value == "":
        return default

    try:
        return int(float(str(value).replace(",", "").strip()))
    except (TypeError, ValueError):
        return default


def timestamp_to_datetime(value: Any):
    timestamp = safe_int(value)
    if timestamp is None or timestamp <= 0:
        return None

    # CoinDCX candle timestamps are normally milliseconds, but some public
    # responses may use seconds. Anything above year-2286 in seconds is treated
    # as milliseconds.
    if timestamp > 10_000_000_000:
        timestamp = timestamp / 1000

    try:
        return datetime.utcfromtimestamp(timestamp)
    except (OverflowError, OSError, ValueError):
        return None


def normalize_candle_row(row) -> dict | None:
    try:
        if isinstance(row, dict):
            candle_time = timestamp_to_datetime(
                _first_value(row, ("time", "timestamp", "t", "start_time", "open_time"))
            )
            normalized = {
                "candle_time": candle_time,
                "open": _first_float(row, ("open", "o")),
                "high": _first_float(row, ("high", "h")),
                "low": _first_float(row, ("low", "l")),
                "close": _first_float(row, ("close", "c")),
                "volume": _first_float(row, ("volume", "v")),
                "quote_volume": _first_float(row, ("quote_volume", "quoteVolume", "q")),
                "trade_count": _first_int(row, ("trade_count", "tradeCount", "trades", "n")),
                "raw": row,
            }
        elif isinstance(row, (list, tuple)):
            normalized = _normalize_sequence_candle(row)
        else:
            return None

        required = ("candle_time", "open", "high", "low", "close")
        if any(normalized.get(key) is None for key in required):
            return None

        return normalized
    except Exception:
        return None


def _normalize_sequence_candle(row: list | tuple) -> dict | None:
    if len(row) < 6:
        return None

    first_as_time = timestamp_to_datetime(row[0])
    last_as_time = timestamp_to_datetime(row[5])

    if first_as_time is not None:
        candle_time = first_as_time
        open_value, high_value, low_value, close_value, volume = row[1:6]
        quote_volume = row[6] if len(row) > 6 else None
        trade_count = row[7] if len(row) > 7 else None
    elif last_as_time is not None:
        open_value, high_value, low_value, close_value, volume = row[0:5]
        candle_time = last_as_time
        quote_volume = row[6] if len(row) > 6 else None
        trade_count = row[7] if len(row) > 7 else None
    else:
        return None

    return {
        "candle_time": candle_time,
        "open": safe_float(open_value),
        "high": safe_float(high_value),
        "low": safe_float(low_value),
        "close": safe_float(close_value),
        "volume": safe_float(volume),
        "quote_volume": safe_float(quote_volume),
        "trade_count": safe_int(trade_count),
        "raw": row,
    }


def _first_value(row: dict, keys: tuple[str, ...]):
    for key in keys:
        if key in row and row.get(key) not in (None, ""):
            return row.get(key)
    return None


def _first_float(row: dict, keys: tuple[str, ...]):
    return safe_float(_first_value(row, keys))


def _first_int(row: dict, keys: tuple[str, ...]):
    return safe_int(_first_value(row, keys))


class CandleCollector:
    def __init__(self, client: CoinDCXPublicClient = None):
        self.client = client or CoinDCXPublicClient()

    def run(self, symbols_limit: int = None, timeframes: list = None) -> dict:
        summary = {
            "active_symbols": 0,
            "symbols_processed": 0,
            "timeframes": [],
            "api_calls": 0,
            "candles_received": 0,
            "candles_inserted_or_updated": 0,
            "skipped": 0,
            "errors": [],
        }

        try:
            selected_timeframes = self._normalize_timeframes(timeframes)
            summary["timeframes"] = selected_timeframes

            active_symbols = self._load_active_symbols()
            summary["active_symbols"] = len(active_symbols)
            if symbols_limit is not None:
                active_symbols = active_symbols[: max(symbols_limit, 0)]

            if not active_symbols:
                message = "No active spot symbols found for candle collection."
                write_health_log(SERVICE_NAME, "warning", message, summary)
                return summary

            for symbol in active_symbols:
                for timeframe in selected_timeframes:
                    try:
                        api_interval = INTERVAL_MAP.get(timeframe, timeframe)
                        start_time, end_time = self._time_window_ms(timeframe)
                        candle_pair = self._candle_pair(symbol)
                        summary["api_calls"] += 1
                        response = self.client.candles(
                            candle_pair,
                            api_interval,
                            start_time=start_time,
                            end_time=end_time,
                        )
                        candle_rows = self._coerce_candle_rows(response)
                        summary["candles_received"] += len(candle_rows)

                        params = self._build_upsert_params(symbol, timeframe, candle_rows)
                        summary["skipped"] += len(candle_rows) - len(params)
                        if params:
                            self._upsert_candles(params)
                            summary["candles_inserted_or_updated"] += len(params)
                    except Exception as exc:
                        error = f"{symbol.get('coindcx_symbol')} {timeframe}: {exc}"
                        logger.warning(error)
                        summary["errors"].append(error)
                    finally:
                        time.sleep(API_SLEEP_SECONDS)

                summary["symbols_processed"] += 1

            status = "warning" if summary["errors"] else "ok"
            message = (
                f"symbols_processed={summary['symbols_processed']}, "
                f"timeframes={','.join(summary['timeframes'])}, "
                f"api_calls={summary['api_calls']}, "
                f"candles_received={summary['candles_received']}, "
                f"inserted_or_updated={summary['candles_inserted_or_updated']}"
            )
            if summary["errors"]:
                message = f"{message}, errors={len(summary['errors'])}"

            write_health_log(SERVICE_NAME, status, message, summary)
            return summary
        except Exception as exc:
            summary["errors"].append(str(exc))
            try:
                write_health_log(SERVICE_NAME, "error", str(exc), summary)
            except Exception:
                pass
            raise

    def _load_active_symbols(self) -> list[dict]:
        return fetch_all(
            """
            SELECT id, coindcx_symbol, base_asset, quote_asset, raw_payload
            FROM spot_symbols
            WHERE is_active = 1
            ORDER BY coindcx_symbol ASC
            """
        )

    def _candle_pair(self, symbol: dict) -> str:
        raw_payload = self._raw_payload_dict(symbol.get("raw_payload"))

        for key in ("pair", "pair_name", "market", "symbol"):
            value = self._clean_pair(raw_payload.get(key))
            if value and PAIR_LIKE_PATTERN.match(value):
                return value

        base_asset = self._clean_asset(symbol.get("base_asset"))
        quote_asset = self._clean_asset(symbol.get("quote_asset"))
        exchange_code = self._clean_asset(
            raw_payload.get("ecode")
            or raw_payload.get("exchange_code")
            or raw_payload.get("exchange")
            or "B"
        )

        if base_asset and quote_asset:
            return f"{exchange_code}-{base_asset}_{quote_asset}"

        return str(symbol.get("coindcx_symbol") or "").strip().upper()

    def _raw_payload_dict(self, raw_payload) -> dict:
        if isinstance(raw_payload, dict):
            return raw_payload
        if isinstance(raw_payload, str) and raw_payload.strip():
            try:
                parsed = json.loads(raw_payload)
                return parsed if isinstance(parsed, dict) else {}
            except ValueError:
                return {}
        return {}

    def _clean_pair(self, value) -> str | None:
        if value is None or value == "":
            return None
        return str(value).strip().upper()

    def _clean_asset(self, value) -> str | None:
        if value is None or value == "":
            return None
        return re.sub(r"[^A-Z0-9]", "", str(value).strip().upper())

    def _normalize_timeframes(self, timeframes: list = None) -> list[str]:
        if not timeframes:
            return DEFAULT_TIMEFRAMES.copy()

        normalized = []
        for timeframe in timeframes:
            value = str(timeframe).strip()
            if value:
                normalized.append(value)
        return normalized or DEFAULT_TIMEFRAMES.copy()

    def _time_window_ms(self, timeframe: str) -> tuple[int, int]:
        end = datetime.utcnow()
        start = end - LOOKBACK_WINDOWS.get(timeframe, timedelta(hours=24))
        return int(start.timestamp() * 1000), int(end.timestamp() * 1000)

    def _coerce_candle_rows(self, response) -> list:
        if isinstance(response, list):
            return response
        if isinstance(response, dict):
            for key in ("data", "candles", "result"):
                value = response.get(key)
                if isinstance(value, list):
                    return value
        return []

    def _build_upsert_params(self, symbol: dict, timeframe: str, candle_rows: list) -> list[tuple]:
        now = datetime.utcnow().strftime("%Y-%m-%d %H:%M:%S")
        params = []

        for row in candle_rows:
            candle = normalize_candle_row(row)
            if candle is None:
                continue

            params.append(
                (
                    symbol["id"],
                    symbol["coindcx_symbol"],
                    timeframe,
                    candle["candle_time"].strftime("%Y-%m-%d %H:%M:%S"),
                    candle["open"],
                    candle["high"],
                    candle["low"],
                    candle["close"],
                    candle["volume"],
                    candle["quote_volume"],
                    candle["trade_count"],
                    json.dumps(candle["raw"], separators=(",", ":"), default=str),
                    now,
                    now,
                )
            )

        return params

    def _upsert_candles(self, params: list[tuple]) -> int:
        return execute_many(
            """
            INSERT INTO candles
                (spot_symbol_id, coindcx_symbol, timeframe, candle_time, open, high,
                 low, close, volume, quote_volume, trade_count, raw_payload,
                 created_at, updated_at)
            VALUES
                (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                open = VALUES(open),
                high = VALUES(high),
                low = VALUES(low),
                close = VALUES(close),
                volume = VALUES(volume),
                quote_volume = VALUES(quote_volume),
                trade_count = VALUES(trade_count),
                raw_payload = VALUES(raw_payload),
                updated_at = VALUES(updated_at)
            """,
            params,
        )
