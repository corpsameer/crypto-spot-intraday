import os
from pathlib import Path

from dotenv import load_dotenv

PYTHON_ROOT = Path(__file__).resolve().parents[1]
load_dotenv(PYTHON_ROOT / ".env")

DB_HOST = os.getenv("DB_HOST", "127.0.0.1")
DB_PORT = int(os.getenv("DB_PORT", "3306"))
DB_DATABASE = os.getenv("DB_DATABASE", "crypto_spot_intraday")
DB_USERNAME = os.getenv("DB_USERNAME", "root")
DB_PASSWORD = os.getenv("DB_PASSWORD", "")

COINDCX_PUBLIC_BASE_URL = os.getenv("COINDCX_PUBLIC_BASE_URL", "https://api.coindcx.com")
COINDCX_CANDLE_BASE_URL = os.getenv("COINDCX_CANDLE_BASE_URL", "https://public.coindcx.com")
LOG_LEVEL = os.getenv("LOG_LEVEL", "INFO")
