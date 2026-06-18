#!/usr/bin/env bash
set -u

# Non-destructive local clean DB E2E checklist helper.
# This script performs preflight and prints the destructive DB reset command,
# but it never runs migrate:fresh automatically.

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

echo "== CryptoSpot local clean DB E2E preflight =="

if [[ -f .env ]]; then
  echo "PASS: .env exists"
  echo "APP_ENV=$(grep -E '^APP_ENV=' .env | cut -d= -f2- || true)"
  echo "APP_URL=$(grep -E '^APP_URL=' .env | cut -d= -f2- || true)"
  echo "DB_DATABASE=$(grep -E '^DB_DATABASE=' .env | cut -d= -f2- || true)"
else
  echo "FAIL: .env missing. Run: cp .env.example .env && php artisan key:generate"
fi

if [[ -d vendor ]]; then
  echo "PASS: vendor/ exists"
else
  echo "FAIL: vendor/ missing. Run: composer install"
fi

if [[ -f artisan && -d vendor ]]; then
  php artisan --version || true
  php artisan optimize:clear || true
else
  echo "SKIP: Artisan checks require vendor/"
fi

if [[ -d python ]]; then
  (cd python && python --version && python -c "import mysql.connector; print('mysql ok')") || \
    echo "FAIL: Python dependency check failed. Run: cd python && python -m venv venv && source venv/bin/activate && pip install -r requirements.txt"
fi

cat <<'MSG'

Manual destructive step (run only after confirming DB_DATABASE is local/dev):
  php artisan migrate:fresh --seed

Then continue with docs/local-clean-db-e2e.md.
MSG
