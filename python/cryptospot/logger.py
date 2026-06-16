import logging
import sys
from pathlib import Path

from cryptospot.config import LOG_LEVEL, PYTHON_ROOT

LOG_DIR = PYTHON_ROOT / "logs"
LOG_FILE = LOG_DIR / "cryptospot.log"
LOG_FORMAT = "%(asctime)s %(levelname)s [%(name)s] %(message)s"

_configured = False


def _configure_logging() -> None:
    global _configured
    if _configured:
        return

    LOG_DIR.mkdir(parents=True, exist_ok=True)
    level = getattr(logging, LOG_LEVEL.upper(), logging.INFO)

    root_logger = logging.getLogger()
    root_logger.setLevel(level)

    formatter = logging.Formatter(LOG_FORMAT)

    console_handler = logging.StreamHandler(sys.stdout)
    console_handler.setLevel(level)
    console_handler.setFormatter(formatter)

    file_handler = logging.FileHandler(LOG_FILE)
    file_handler.setLevel(level)
    file_handler.setFormatter(formatter)

    root_logger.handlers.clear()
    root_logger.addHandler(console_handler)
    root_logger.addHandler(file_handler)

    _configured = True


def get_logger(name: str) -> logging.Logger:
    _configure_logging()
    return logging.getLogger(name)
