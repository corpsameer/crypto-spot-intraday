<?php

namespace Database\Seeders;

use App\Models\AppSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AppSettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            ['scanner.watchlist_score_threshold', '70', 'integer', 'scanner'], ['scanner.strong_score_threshold', '80', 'integer', 'scanner'], ['scanner.min_15m_change_percent', '2', 'decimal', 'scanner'], ['scanner.min_1h_change_percent', '3', 'decimal', 'scanner'], ['scanner.min_15m_volume_spike', '2.5', 'decimal', 'scanner'], ['scanner.min_1h_volume_spike', '2', 'decimal', 'scanner'], ['scanner.max_distance_from_24h_high_percent', '3', 'decimal', 'scanner'], ['scanner.preferred_max_spread_percent', '0.25', 'decimal', 'scanner'], ['scanner.max_holding_hours', '72', 'integer', 'scanner'],
            ['trade.default_sl_percent', '-4', 'decimal', 'trade'], ['trade.tp1_percent', '5', 'decimal', 'trade'], ['trade.tp2_percent', '10', 'decimal', 'trade'], ['trade.default_notional_usdt', '100', 'decimal', 'trade'],
            ['trailing.activate_at_percent', '10', 'decimal', 'trailing'], ['trailing.lock_at_10_percent', '6', 'decimal', 'trailing'], ['trailing.lock_at_12_percent', '8', 'decimal', 'trailing'], ['trailing.lock_at_15_percent', '11', 'decimal', 'trailing'], ['trailing.lock_at_20_percent', '15', 'decimal', 'trailing'], ['trailing.lock_at_25_percent', '19', 'decimal', 'trailing'], ['trailing.lock_at_30_percent', '24', 'decimal', 'trailing'],
            ['scoring.weight_15m_momentum', '15', 'integer', 'scoring'], ['scoring.weight_1h_momentum', '15', 'integer', 'scoring'], ['scoring.weight_volume_spike', '25', 'integer', 'scoring'], ['scoring.weight_breakout_near_high', '15', 'integer', 'scoring'], ['scoring.weight_liquidity_spread', '15', 'integer', 'scoring'], ['scoring.weight_relative_strength_btc', '10', 'integer', 'scoring'], ['scoring.weight_market_context', '5', 'integer', 'scoring'], ['scoring.max_risk_penalty', '-20', 'integer', 'scoring'],
            ['system.exchange', 'coindcx', 'string', 'system', false], ['system.market_type', 'spot', 'string', 'system', false], ['system.real_trading_enabled', 'false', 'boolean', 'system', false],
        ];

        foreach ($settings as $setting) {
            [$key, $value, $type, $group, $editable] = $setting + [4 => true];
            AppSetting::updateOrCreate(
                ['key' => $key],
                [
                    'value' => $value,
                    'value_type' => $type,
                    'group' => $group,
                    'label' => Str::headline(Str::after($key, '.')),
                    'description' => 'Default '.$group.' setting for '.$key.'.',
                    'is_editable' => $editable,
                ]
            );
        }
    }
}
