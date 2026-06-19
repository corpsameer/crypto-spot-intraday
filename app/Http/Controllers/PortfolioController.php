<?php

namespace App\Http\Controllers;

use App\Services\PortfolioAnalyticsService;
use Illuminate\View\View;

class PortfolioController extends Controller
{
    public function index(PortfolioAnalyticsService $analytics): View
    {
        $portfolio = $analytics->getActivePortfolio();
        if (! $portfolio) {
            return view('portfolio.index', ['portfolio' => null]);
        }

        return view('portfolio.index', [
            'portfolio' => $portfolio,
            'summary' => $analytics->getSummary($portfolio),
            'capitalUsage' => $analytics->getCapitalUsage($portfolio),
            'monthlyGrowth' => $analytics->getMonthlyGrowth($portfolio),
            'openTrades' => $analytics->getOpenTrades($portfolio),
            'pendingPlans' => $analytics->getPendingPlans($portfolio),
            'recentClosedTrades' => $analytics->getRecentClosedTrades($portfolio),
            'recentTransactions' => $analytics->getRecentTransactions($portfolio),
            'allocationSummary' => $analytics->getAllocationSummary($portfolio),
            'reconciliation' => $analytics->getReconciliation($portfolio),
        ]);
    }
}
