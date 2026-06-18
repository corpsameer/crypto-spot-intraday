<?php

namespace App\Console\Commands;

class CryptoSpotRunDailyGainers extends CryptoSpotPythonCommand
{
    protected $signature = 'cryptospot:daily-gainers {--quote= : Quote filter, defaults to CRYPTOSPOT_QUOTE_FILTER or USDT} {--limit= : Row limit, defaults to CRYPTOSPOT_DAILY_GAINER_LIMIT or 100}';

    protected $description = 'Run the CryptoSpot daily gainer leaderboard builder once.';

    public function handle(): int
    {
        return $this->runPythonScript([
            'scripts/run_daily_gainer_leaderboard_once.py',
            '--quote', (string) ($this->option('quote') ?: env('CRYPTOSPOT_QUOTE_FILTER', 'USDT')),
            '--limit', (string) ($this->option('limit') ?: env('CRYPTOSPOT_DAILY_GAINER_LIMIT', 100)),
        ]);
    }
}
