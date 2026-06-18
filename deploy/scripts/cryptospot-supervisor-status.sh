#!/usr/bin/env bash
set -euo pipefail

sudo supervisorctl status cryptospot-realtime-monitors
sudo tail -n 50 /var/log/cryptospot/realtime-monitors.log
