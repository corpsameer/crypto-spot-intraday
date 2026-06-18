#!/usr/bin/env bash
set -euo pipefail

sudo supervisorctl stop cryptospot-realtime-monitors
sudo supervisorctl status cryptospot-realtime-monitors
