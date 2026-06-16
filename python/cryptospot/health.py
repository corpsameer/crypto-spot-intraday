import json
from datetime import datetime

from cryptospot.db import execute


def write_health_log(service_name: str, status: str, message: str = None, meta: dict = None):
    now = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    meta_json = json.dumps(meta) if meta is not None else None

    return execute(
        """
        INSERT INTO system_health_logs
            (service_name, status, message, checked_at, meta, created_at, updated_at)
        VALUES
            (%s, %s, %s, %s, %s, %s, %s)
        """,
        (service_name, status, message, now, meta_json, now, now),
    )
