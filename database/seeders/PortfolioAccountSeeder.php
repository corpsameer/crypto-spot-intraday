<?php

namespace Database\Seeders;

use App\Models\PortfolioAccount;
use Illuminate\Database\Seeder;

class PortfolioAccountSeeder extends Seeder
{
    public function run(): void
    {
        PortfolioAccount::updateOrCreate(
            [
                'name' => 'Default INR Portfolio',
                'currency' => 'INR',
            ],
            [
                'starting_capital' => 100000.00,
                'current_cash' => 100000.00,
                'reserved_cash' => 0.00,
                'deployed_capital' => 0.00,
                'realized_pnl' => 0.00,
                'unrealized_pnl' => 0.00,
                'total_equity' => 100000.00,
                'total_return_percent' => 0,
                'max_open_trades' => 3,
                'preferred_open_trades' => 2,
                'max_pending_trade_plans' => 3,
                'reserve_cash_percent' => 10,
                'min_trade_capital' => 10000,
                'max_trade_capital' => 40000,
                'is_active' => true,
                'raw_payload' => [
                    'source' => 'PortfolioAccountSeeder',
                    'purpose' => 'INR 1 lakh paper portfolio for capital-aware spot simulation',
                ],
            ]
        );
    }
}
