<?php

namespace App\Http\Controllers;

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

        return view('dashboard', compact('modules'));
    }
}
