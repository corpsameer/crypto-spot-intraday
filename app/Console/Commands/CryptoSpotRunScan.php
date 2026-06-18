<?php

namespace App\Console\Commands;

class CryptoSpotRunScan extends CryptoSpotPythonCommand
{
    protected $signature = 'cryptospot:scan {--quote= : Optional quote filter if the scan runner supports it} {--limit= : Optional symbol limit for testing if the scan runner supports it}';

    protected $description = 'Run one CryptoSpot full-market scan pipeline.';

    public function handle(): int
    {
        $arguments = ['scripts/run_manual_scan_once.py'];

        if ($this->option('quote')) {
            $arguments[] = '--quote';
            $arguments[] = (string) $this->option('quote');
        }

        if ($this->option('limit')) {
            $arguments[] = '--limit';
            $arguments[] = (string) $this->option('limit');
        }

        return $this->runPythonScript($arguments);
    }
}
