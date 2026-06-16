import time

import requests

from cryptospot.config import COINDCX_PUBLIC_BASE_URL

COINDCX_CANDLE_BASE_URL = "https://public.coindcx.com"


class CoinDCXPublicClient:
    def __init__(self, base_url: str = None):
        self.base_url = (base_url or COINDCX_PUBLIC_BASE_URL).rstrip("/")

    def _get(self, path: str, params: dict = None, base_url: str = None):
        request_base_url = (base_url or self.base_url).rstrip("/")
        url = f"{request_base_url}/{path.lstrip('/')}"
        last_error = None

        for attempt in range(3):
            try:
                response = requests.get(url, params=params, timeout=15)
                response.raise_for_status()
                try:
                    return response.json()
                except ValueError as exc:
                    raise RuntimeError(f"CoinDCX response was not valid JSON for {url}") from exc
            except requests.RequestException as exc:
                last_error = exc
                if attempt < 2:
                    time.sleep(1)
                    continue
                raise RuntimeError(f"CoinDCX public request failed for {url}: {exc}") from exc

        raise RuntimeError(f"CoinDCX public request failed for {url}: {last_error}")

    def markets_details(self) -> list:
        return self._get("/exchange/v1/markets_details")

    def ticker(self) -> list:
        return self._get("/exchange/ticker")

    def orderbook(self, pair: str) -> dict:
        return self._get("/market_data/orderbook", {"pair": pair})

    def candles(self, pair: str, interval: str, start_time: int = None, end_time: int = None) -> list:
        params = {"pair": pair, "interval": interval}
        if start_time is not None:
            params["startTime"] = start_time
        if end_time is not None:
            params["endTime"] = end_time
        return self._get("/market_data/candles/", params, base_url=COINDCX_CANDLE_BASE_URL)
