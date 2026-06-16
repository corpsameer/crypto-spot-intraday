<?php

namespace App\Http\Controllers;

use App\Models\SpotSymbol;
use App\Models\SystemHealthLog;
use App\Services\SpotUniverseSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Throwable;

class SpotSymbolController extends Controller
{
    public function index(): View
    {
        return view('spot-symbols.index', [
            'symbols' => SpotSymbol::query()->orderByDesc('is_active')->orderBy('coindcx_symbol')->paginate(50),
            'symbolCount' => SpotSymbol::count(),
            'activeCount' => SpotSymbol::where('is_active', true)->count(),
            'latestSyncLog' => SystemHealthLog::query()
                ->where('service_name', 'coindcx_spot_universe_sync')
                ->latest('checked_at')
                ->first(),
        ]);
    }

    public function sync(SpotUniverseSyncService $syncService): RedirectResponse
    {
        try {
            $result = $syncService->sync();
        } catch (Throwable $exception) {
            return redirect()
                ->route('cryptospot.spot-symbols.index')
                ->withErrors(['sync' => 'CoinDCX spot universe sync failed: '.$exception->getMessage()]);
        }

        return redirect()
            ->route('cryptospot.spot-symbols.index')
            ->with('success', "CoinDCX spot universe synced. {$result['synced']} symbols updated, {$result['deactivated']} marked inactive.");
    }
}
