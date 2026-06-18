<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SimulatedTrade extends Model
{
    use HasFactory;

    protected $fillable = [
        'scan_run_id',
        'scan_result_id',
        'candidate_watchlist_id',
        'trade_plan_id',
        'spot_symbol_id',
        'scanner_metric_id',
        'coindcx_symbol',
        'api_pair',
        'base_asset',
        'quote_asset',
        'side',
        'status',
        'source',
        'planned_entry_price',
        'trigger_price',
        'entry_price',
        'entry_trigger_price',
        'entry_triggered_at',
        'quantity',
        'notional_usdt',
        'tp1_price',
        'tp2_price',
        'sl_price',
        'trailing_start_price',
        'current_trailing_sl_price',
        'trailing_stop_price',
        'trailing_active',
        'tp1_percent',
        'tp2_percent',
        'sl_percent',
        'latest_price',
        'highest_price',
        'lowest_price',
        'max_gain_percent',
        'max_drawdown_percent',
        'current_pnl_percent',
        'current_gain_percent',
        'final_pnl_percent',
        'tp1_hit_at',
        'tp2_hit_at',
        'sl_hit_at',
        'trailing_started_at',
        'trailing_activated_at',
        'trailing_stopped_at',
        'closed_at',
        'expires_at',
        'close_price',
        'close_reason',
        'exit_price',
        'exit_reason',
        'score',
        'score_label',
        'entry_strategy',
        'notes',
        'portfolio_account_id',
        'allocated_capital',
        'allocation_percent',
        'entry_value',
        'current_value',
        'close_value',
        'unrealized_pnl_amount',
        'realized_pnl_amount',
        'fees_amount',
        'net_pnl_amount',
        'capital_released_at',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'trailing_active' => 'boolean',
            'raw_payload' => 'array',
            'entry_triggered_at' => 'datetime',
            'tp1_hit_at' => 'datetime',
            'tp2_hit_at' => 'datetime',
            'sl_hit_at' => 'datetime',
            'trailing_started_at' => 'datetime',
            'trailing_activated_at' => 'datetime',
            'trailing_stopped_at' => 'datetime',
            'closed_at' => 'datetime',
            'expires_at' => 'datetime',
            'planned_entry_price' => 'decimal:12',
            'trigger_price' => 'decimal:12',
            'entry_price' => 'decimal:12',
            'entry_trigger_price' => 'decimal:12',
            'quantity' => 'decimal:12',
            'notional_usdt' => 'decimal:12',
            'tp1_price' => 'decimal:12',
            'tp2_price' => 'decimal:12',
            'sl_price' => 'decimal:12',
            'trailing_start_price' => 'decimal:12',
            'current_trailing_sl_price' => 'decimal:12',
            'trailing_stop_price' => 'decimal:12',
            'latest_price' => 'decimal:12',
            'highest_price' => 'decimal:12',
            'lowest_price' => 'decimal:12',
            'close_price' => 'decimal:12',
            'exit_price' => 'decimal:12',
            'tp1_percent' => 'decimal:4',
            'tp2_percent' => 'decimal:4',
            'sl_percent' => 'decimal:4',
            'max_gain_percent' => 'decimal:4',
            'max_drawdown_percent' => 'decimal:4',
            'current_pnl_percent' => 'decimal:4',
            'current_gain_percent' => 'decimal:4',
            'final_pnl_percent' => 'decimal:4',
            'score' => 'decimal:4',
            'allocated_capital' => 'decimal:2',
            'allocation_percent' => 'decimal:4',
            'entry_value' => 'decimal:2',
            'current_value' => 'decimal:2',
            'close_value' => 'decimal:2',
            'unrealized_pnl_amount' => 'decimal:2',
            'realized_pnl_amount' => 'decimal:2',
            'fees_amount' => 'decimal:2',
            'net_pnl_amount' => 'decimal:2',
            'capital_released_at' => 'datetime',
        ];
    }

    public function scanRun(): BelongsTo { return $this->belongsTo(ScanRun::class); }
    public function scanResult(): BelongsTo { return $this->belongsTo(ScanResult::class); }
    public function candidateWatchlist(): BelongsTo { return $this->belongsTo(CandidateWatchlist::class); }
    public function tradePlan(): BelongsTo { return $this->belongsTo(TradePlan::class); }
    public function spotSymbol(): BelongsTo { return $this->belongsTo(SpotSymbol::class); }
    public function scannerMetric(): BelongsTo { return $this->belongsTo(ScannerMetric::class); }
    public function events(): HasMany { return $this->hasMany(TradeEvent::class); }
    public function tradeEvents(): HasMany { return $this->events(); }
    public function portfolioAccount(): BelongsTo { return $this->belongsTo(PortfolioAccount::class); }
}
