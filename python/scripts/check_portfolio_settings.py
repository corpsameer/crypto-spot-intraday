import sys
from pathlib import Path
import json

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from cryptospot.settings import get_portfolio_settings


if __name__ == "__main__":
    print(json.dumps(get_portfolio_settings(), indent=2, sort_keys=True))
