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
            ['scanner.watchlist_score_threshold', '50', 'integer', 'scanner'], ['scanner.strong_score_threshold', '60', 'integer', 'scanner'], ['scanner.min_15m_change_percent', '2', 'decimal', 'scanner'], ['scanner.min_1h_change_percent', '3', 'decimal', 'scanner'], ['scanner.min_15m_volume_spike', '2.5', 'decimal', 'scanner'], ['scanner.min_1h_volume_spike', '2', 'decimal', 'scanner'], ['scanner.max_distance_from_24h_high_percent', '3', 'decimal', 'scanner'], ['scanner.preferred_max_spread_percent', '0.25', 'decimal', 'scanner'], ['scanner.max_holding_hours', '72', 'integer', 'scanner'],
            ['scanner.enable_fallback_candidates', 'true', 'boolean', 'scanner', true, 'Enable Fallback Candidates', 'If too few symbols pass the watchlist threshold, select top scored symbols above fallback minimum as fallback candidates.'],
            ['scanner.min_fallback_candidate_score', '40', 'decimal', 'scanner', true, 'Minimum Fallback Candidate Score', 'Minimum final score required for top-N fallback candidate selection.'],
            ['scanner.min_required_candidates', '3', 'integer', 'scanner', true, 'Minimum Required Candidates Per Scan', 'Minimum number of scored candidates to carry forward if fallback candidates are available.'],
            ['scanner.fallback_label', 'fallback', 'string', 'scanner', true, 'Fallback Candidate Label', 'Label used for candidates selected by top-N fallback logic.'],
            ['trade.default_sl_percent', '-4', 'decimal', 'trade'], ['trade.tp1_percent', '5', 'decimal', 'trade'], ['trade.tp2_percent', '10', 'decimal', 'trade'], ['trade.default_notional_usdt', '100', 'decimal', 'trade'],
            ['trailing.activate_at_percent', '10', 'decimal', 'trailing'], ['trailing.lock_at_10_percent', '6', 'decimal', 'trailing'], ['trailing.lock_at_12_percent', '8', 'decimal', 'trailing'], ['trailing.lock_at_15_percent', '11', 'decimal', 'trailing'], ['trailing.lock_at_20_percent', '15', 'decimal', 'trailing'], ['trailing.lock_at_25_percent', '19', 'decimal', 'trailing'], ['trailing.lock_at_30_percent', '24', 'decimal', 'trailing'],
            ['trailing.enabled', 'true', 'boolean', 'trailing', true, 'Enable Trailing After TP2', 'Enables trailing stop after TP2 is hit for simulated trades.'],
            ['trailing.activation_after_tp2', 'true', 'boolean', 'trailing', true, 'Activate After TP2', 'Trailing activates only after TP2 is hit.'],
            ['trailing.levels', '[{"gain_percent":10,"lock_percent":6},{"gain_percent":12,"lock_percent":8},{"gain_percent":15,"lock_percent":11},{"gain_percent":20,"lock_percent":15},{"gain_percent":25,"lock_percent":19},{"gain_percent":30,"lock_percent":24}]', 'json', 'trailing', true, 'Trailing Levels', 'Gain-to-lock mapping after TP2. Example: at 10% max gain, lock 6%.'],
            ['trailing.min_update_step_percent', '0.25', 'decimal', 'trailing', true, 'Minimum Trailing Update Step %', 'Minimum improvement in locked gain required before creating a TRAILING_UPDATED event.'],
            ['trailing.close_on_trailing_stop', 'true', 'boolean', 'trailing', true, 'Close On Trailing Stop', 'Closes simulated trade when latest price falls to or below current trailing SL.'],
            ['scoring.weight_15m_momentum', '15', 'integer', 'scoring'], ['scoring.weight_1h_momentum', '15', 'integer', 'scoring'], ['scoring.weight_volume_spike', '25', 'integer', 'scoring'], ['scoring.weight_breakout_near_high', '15', 'integer', 'scoring'], ['scoring.weight_liquidity_spread', '15', 'integer', 'scoring'], ['scoring.weight_relative_strength_btc', '10', 'integer', 'scoring'], ['scoring.weight_market_context', '5', 'integer', 'scoring'], ['scoring.max_risk_penalty', '-20', 'integer', 'scoring'],

            ['scan.enabled', 'true', 'boolean', 'scan', true, 'Enable Scheduled Scans', 'Enables scheduled/manual scan workflow. This does not start a continuous all-market scanner.'],
            ['scan.timezone', 'Asia/Kolkata', 'string', 'scan', true, 'Scan Timezone', 'Timezone used for scheduled scan times.'],
            ['scan.scheduled_times', '["09:00","14:00","19:00","22:30"]', 'json', 'scan', true, 'Scheduled Scan Times', 'Daily scan times in 24-hour HH:MM format. Full scans should run only at these configured times or manually.'],
            ['scan.default_quote_filter', 'USDT', 'string', 'scan', true, 'Default Quote Filter', 'Default quote asset for scan runs, for example USDT or INR. Use ALL for all quote assets.'],
            ['scan.max_prefilter_symbols', '50', 'integer', 'scan', true, 'Max Prefilter Symbols', 'Maximum number of symbols allowed into candle/metrics/scoring stage after ticker prefilter.'],
            ['scan.max_final_candidates', '10', 'integer', 'scan', true, 'Max Final Candidates', 'Maximum number of final scan candidates/watchlist candidates to keep from each scan.'],
            ['scan.allow_manual_scan', 'true', 'boolean', 'scan', true, 'Allow Manual Scan', 'Allows scans to be started manually from CLI or later from UI.'],
            ['scan.prevent_overlap', 'true', 'boolean', 'scan', true, 'Prevent Overlapping Scans', 'Prevents a new scan from starting if another scan_run is already running.'],
            ['scan.running_timeout_minutes', '45', 'integer', 'scan', true, 'Running Scan Timeout Minutes', 'If a scan_run remains running longer than this, it may be considered stale/failed by later monitor logic.'],
            ['scan.fetch_orderbook_for_candidates', 'true', 'boolean', 'scan', true, 'Fetch Orderbook For Candidates', 'Fetch orderbook/liquidity only for prefiltered scan candidates, not for all coins.'],
            ['scan.fetch_candles_for_candidates', 'true', 'boolean', 'scan', true, 'Fetch Candles For Candidates', 'Fetch candles only for prefiltered scan candidates, not continuously for all coins.'],
            ['scan.create_trade_plans', 'true', 'boolean', 'scan', true, 'Create Trade Plans', 'Allows scan results to create pending trade plans after scoring.'],
            ['scan.scan_result_retention_days', '30', 'integer', 'scan', true, 'Scan Result Retention Days', 'Number of days to retain scan_runs and scan_results before future cleanup logic may archive/delete old scan data.'],
            ['prefilter.min_24h_quote_volume', '50000', 'decimal', 'prefilter', true, 'Minimum 24h Quote Volume', 'Basic ticker prefilter. Rejects low-liquidity symbols before candle fetching.'],
            ['prefilter.min_24h_change_percent', '0', 'decimal', 'prefilter', true, 'Minimum 24h Change %', 'Basic ticker prefilter for 24h change. Use 0 to require non-negative 24h change.'],
            ['prefilter.min_abs_24h_change_percent', '1', 'decimal', 'prefilter', true, 'Minimum Absolute 24h Change %', 'Basic ticker prefilter for movement. Symbol should have at least this absolute 24h move.'],
            ['prefilter.min_last_price', '0', 'decimal', 'prefilter', true, 'Minimum Last Price', 'Optional basic price floor. Use 0 to disable.'],
            ['prefilter.max_spread_percent', '0.5', 'decimal', 'prefilter', true, 'Maximum Spread %', 'Optional spread filter when ticker/orderbook spread is available.'],
            ['prefilter.exclude_stablecoins', 'true', 'boolean', 'prefilter', true, 'Exclude Stablecoins', 'Excludes obvious stablecoin/stable pairs from scan candidates.'],
            ['prefilter.stablecoin_assets', '["USDT","USDC","BUSD","DAI","TUSD","FDUSD"]', 'json', 'prefilter', true, 'Stablecoin Assets', 'Base assets to exclude when exclude_stablecoins is enabled.'],
            ['prefilter.allowed_quotes', '["USDT"]', 'json', 'prefilter', true, 'Allowed Quote Assets', 'Quote assets allowed during scans. Use ["USDT"] for MVP unless INR scanning is intentionally enabled.'],
            ['monitor.candidate_refresh_seconds', '60', 'integer', 'monitor', true, 'Candidate Refresh Seconds', 'Lightweight monitor interval for shortlisted watchlist candidates only.'],
            ['monitor.trade_plan_refresh_seconds', '30', 'integer', 'monitor', true, 'Trade Plan Refresh Seconds', 'Lightweight monitor interval for pending trade plans only.'],
            ['monitor.active_trade_refresh_seconds', '15', 'integer', 'monitor', true, 'Active Trade Refresh Seconds', 'Continuous active simulated trade monitor interval for TP, SL, trailing, and expiry checks.'],
            ['monitor.system_health_refresh_seconds', '60', 'integer', 'monitor', true, 'System Health Refresh Seconds', 'Interval for lightweight system health checks.'],
            ['trade_plan.default_valid_hours', '6', 'integer', 'trade_plan', true, 'Default Trade Plan Valid Hours', 'Number of hours a pending trade plan remains valid if entry is not triggered.'],
            ['trade_plan.breakout_entry_buffer_percent', '0.2', 'decimal', 'trade_plan', true, 'Breakout Entry Buffer %', 'Optional buffer above reference/breakout price for trigger calculation.'],
            ['trade_plan.pullback_entry_percent', '1.5', 'decimal', 'trade_plan', true, 'Pullback Entry %', 'Pullback percentage from reference price for pullback entry plans.'],
            ['trade_plan.default_tp1_percent', '5', 'decimal', 'trade_plan', true, 'Default TP1 %', 'Default take profit 1 percentage from entry.'],
            ['trade_plan.default_tp2_percent', '10', 'decimal', 'trade_plan', true, 'Default TP2 %', 'Default take profit 2 percentage from entry.'],
            ['trade_plan.default_sl_percent', '-4', 'decimal', 'trade_plan', true, 'Default SL %', 'Default stop loss percentage from entry.'],

            ['portfolio.enabled', 'true', 'boolean', 'portfolio', true, 'Enable Portfolio Simulation', 'Enables capital-aware paper portfolio simulation.'],
            ['portfolio.default_account_name', 'Default INR Portfolio', 'string', 'portfolio', true, 'Default Portfolio Account', 'Name of the active portfolio account used for simulation.'],
            ['portfolio.currency', 'INR', 'string', 'portfolio', true, 'Portfolio Currency', 'Currency used for paper capital tracking.'],
            ['portfolio.starting_capital', '100000', 'decimal', 'portfolio', true, 'Starting Capital', 'Default paper capital for INR portfolio simulation.'],
            ['portfolio.max_open_trades', '3', 'integer', 'portfolio', true, 'Max Open Trades', 'Maximum number of open simulated trades at a time.'],
            ['portfolio.preferred_open_trades', '2', 'integer', 'portfolio', true, 'Preferred Open Trades', 'Preferred number of open trades before becoming conservative.'],
            ['portfolio.max_pending_trade_plans', '3', 'integer', 'portfolio', true, 'Max Pending Trade Plans', 'Maximum number of pending/watching trade plans allowed at a time.'],
            ['portfolio.max_total_open_opportunities', '3', 'integer', 'portfolio', true, 'Max Open Opportunities', 'Maximum open trades plus pending trade plans combined.'],
            ['portfolio.reserve_cash_percent', '10', 'decimal', 'portfolio', true, 'Reserve Cash %', 'Percentage of portfolio kept as cash reserve.'],
            ['portfolio.min_trade_capital', '10000', 'decimal', 'portfolio', true, 'Minimum Trade Capital', 'Minimum INR allocation required to create a trade plan.'],
            ['portfolio.max_trade_capital', '40000', 'decimal', 'portfolio', true, 'Maximum Trade Capital', 'Maximum INR allocation allowed for one trade.'],
            ['portfolio.strong_score_min', '70', 'decimal', 'portfolio', true, 'Strong Score Minimum', 'Minimum score for strongest capital allocation bucket.'],
            ['portfolio.watchlist_score_min', '50', 'decimal', 'portfolio', true, 'Watchlist Score Minimum', 'Minimum score for normal watchlist allocation bucket.'],
            ['portfolio.strong_allocation_capital', '40000', 'decimal', 'portfolio', true, 'Strong Allocation Capital', 'Preferred INR allocation for strong candidates.'],
            ['portfolio.watchlist_allocation_capital', '30000', 'decimal', 'portfolio', true, 'Watchlist Allocation Capital', 'Preferred INR allocation for watchlist candidates.'],
            ['portfolio.weak_allocation_capital', '20000', 'decimal', 'portfolio', true, 'Weak/Fallback Allocation Capital', 'Preferred INR allocation for weak or fallback candidates.'],
            ['portfolio.fallback_allocation_capital', '15000', 'decimal', 'portfolio', true, 'Fallback Allocation Capital', 'Preferred INR allocation for fallback-selected candidates.'],
            ['portfolio.prevent_duplicate_symbol', 'true', 'boolean', 'portfolio', true, 'Prevent Duplicate Symbol', 'Prevents multiple active opportunities for the same symbol.'],
            ['portfolio.symbol_cooldown_hours', '24', 'integer', 'portfolio', true, 'Symbol Cooldown Hours', 'Number of hours before same symbol can be planned again after a trade closes.'],
            ['portfolio.cooldown_after_sl_hours', '24', 'integer', 'portfolio', true, 'Cooldown After SL Hours', 'Cooldown for a symbol after stop-loss close.'],
            ['portfolio.cooldown_after_win_hours', '12', 'integer', 'portfolio', true, 'Cooldown After Winning Close Hours', 'Cooldown for a symbol after trailing/positive close.'],
            ['portfolio.cooldown_after_expiry_hours', '6', 'integer', 'portfolio', true, 'Cooldown After Expiry Hours', 'Cooldown for a symbol after untriggered/expired opportunity.'],
            ['portfolio.reserve_capital_on_plan_creation', 'true', 'boolean', 'portfolio', true, 'Reserve Capital On Plan Creation', 'Reserves allocated capital when trade plan is created.'],
            ['portfolio.release_capital_on_plan_expiry', 'true', 'boolean', 'portfolio', true, 'Release Capital On Plan Expiry', 'Releases reserved capital when trade plan expires without entry.'],
            ['portfolio.include_pending_plans_in_capital_check', 'true', 'boolean', 'portfolio', true, 'Include Pending Plans In Capital Check', 'Counts pending plans as reserved opportunities.'],
            ['portfolio.allow_multiple_strategies_same_symbol', 'false', 'boolean', 'portfolio', true, 'Allow Multiple Strategies Same Symbol', 'Allows both breakout and pullback plan for same symbol if true. Recommended false.'],
            ['portfolio.paper_fees_enabled', 'false', 'boolean', 'portfolio', true, 'Enable Paper Fees', 'Enables simulated fee deduction later.'],
            ['portfolio.paper_fee_percent', '0', 'decimal', 'portfolio', true, 'Paper Fee %', 'Fee percentage for paper simulation. Kept zero for initial MVP.'],
            ['portfolio.monthly_growth_tracking_enabled', 'true', 'boolean', 'portfolio', true, 'Monthly Growth Tracking', 'Enables monthly growth analytics based on portfolio equity.'],
            ['portfolio.reset_allowed', 'false', 'boolean', 'portfolio', false, 'Portfolio Reset Allowed', 'Safety flag. Reset actions should not be allowed in production unless enabled.'],
            ['system.exchange', 'coindcx', 'string', 'system', false], ['system.market_type', 'spot', 'string', 'system', false], ['system.real_trading_enabled', 'false', 'boolean', 'system', false],
            ['retention.candles_1m_days', '3', 'integer', 'retention', true, '1m candle retention days', 'Delete 1m candle rows older than this many days.'],
            ['retention.candles_5m_days', '7', 'integer', 'retention', true, '5m candle retention days', 'Delete 5m candle rows older than this many days.'],
            ['retention.candles_15m_days', '14', 'integer', 'retention', true, '15m candle retention days', 'Delete 15m candle rows older than this many days.'],
            ['retention.candles_1h_days', '45', 'integer', 'retention', true, '1h candle retention days', 'Delete 1h candle rows older than this many days.'],
            ['retention.candles_4h_days', '90', 'integer', 'retention', true, '4h candle retention days', 'Delete 4h candle rows older than this many days.'],
            ['retention.scanner_metrics_days', '14', 'integer', 'retention', true, 'Scanner metrics retention days', 'Delete scanner metric rows older than this many days.'],
            ['retention.market_snapshots_days', '30', 'integer', 'retention', true, 'Market snapshots retention days', 'Delete market snapshot rows older than this many days.'],
            ['retention.system_health_logs_days', '30', 'integer', 'retention', true, 'System health logs retention days', 'Delete system health log rows older than this many days.'],
        ];

        foreach ($settings as $setting) {
            [$key, $value, $type, $group, $editable, $label, $description] = $setting + [4 => true, 5 => null, 6 => null];
            $existing = AppSetting::where('key', $key)->first();

            AppSetting::updateOrCreate(
                ['key' => $key],
                [
                    'value' => $existing?->value ?? $value,
                    'value_type' => $type,
                    'group' => $group,
                    'label' => $label ?? Str::headline(Str::after($key, '.')),
                    'description' => $description ?? 'Default '.$group.' setting for '.$key.'.',
                    'is_editable' => $editable,
                ]
            );
        }
    }
}
