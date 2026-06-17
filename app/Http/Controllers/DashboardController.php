<?php

namespace App\Http\Controllers;

use App\Models\ScanRun;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $modules = [
            'Universe Sync',
            'Market Snapshots',
            'Candles',
            'Metrics Engine',
            'Scoring Engine',
            'Scan Runs',
            'Scan Results',
            'Candidate Watchlist',
            'Trade Plans',
            'Simulated Trades',
            'Missed Gainers',
            'System Health',
            'Settings',
        ];

        $latestScanRun = ScanRun::query()
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();

        return view('dashboard', compact('modules', 'latestScanRun'));
    }
}
