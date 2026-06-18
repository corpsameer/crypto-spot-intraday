<?php

namespace App\Console\Commands;

class CryptoSpotRunMissedGainers extends CryptoSpotPythonCommand
{
    protected $signature = 'cryptospot:missed-gainers {--quote= : Quote filter, defaults to CRYPTOSPOT_QUOTE_FILTER or USDT} {--min-change= : Minimum 24h change percent, defaults to CRYPTOSPOT_MISSED_GAINER_MIN_CHANGE or 10} {--limit= : Row limit, defaults to CRYPTOSPOT_MISSED_GAINER_LIMIT or 100}';

    protected $description = 'Run the CryptoSpot missed gainer analyzer once.';

    public function handle(): int
    {
        return $this->runPythonScript([
            'scripts/run_missed_gainer_analyzer_once.py',
            '--quote', (string) ($this->option('quote') ?: env('CRYPTOSPOT_QUOTE_FILTER', 'USDT')),
            '--min-change', (string) ($this->option('min-change') ?: env('CRYPTOSPOT_MISSED_GAINER_MIN_CHANGE', 10)),
            '--limit', (string) ($this->option('limit') ?: env('CRYPTOSPOT_MISSED_GAINER_LIMIT', 100)),
        ]);
    }
}
