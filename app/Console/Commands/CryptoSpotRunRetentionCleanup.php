<?php

namespace App\Console\Commands;

class CryptoSpotRunRetentionCleanup extends CryptoSpotPythonCommand
{
    protected $signature = 'cryptospot:cleanup';

    protected $description = 'Run the CryptoSpot retention cleanup once.';

    public function handle(): int
    {
        return $this->runPythonScript(['scripts/run_data_cleanup_once.py']);
    }
}
