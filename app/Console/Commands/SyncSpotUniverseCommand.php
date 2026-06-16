<?php

namespace App\Console\Commands;

use App\Services\SpotUniverseSyncService;
use Illuminate\Console\Command;

class SyncSpotUniverseCommand extends Command
{
    protected $signature = 'cryptospot:sync-spot-universe';

    protected $description = 'Sync CoinDCX spot markets into the local spot_symbols table.';

    public function handle(SpotUniverseSyncService $syncService): int
    {
        $this->info('Syncing CoinDCX spot universe...');

        $result = $syncService->sync();

        $this->info("Synced {$result['synced']} spot symbols.");
        $this->line("Skipped {$result['skipped']} non-spot or invalid markets.");
        $this->line("Marked {$result['deactivated']} missing symbols inactive.");

        return self::SUCCESS;
    }
}
