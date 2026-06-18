#!/usr/bin/env bash
set -euo pipefail

sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart cryptospot-realtime-monitors
sudo supervisorctl status cryptospot-realtime-monitors
