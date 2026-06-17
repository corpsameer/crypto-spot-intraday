<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TradeEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'simulated_trade_id',
        'trade_plan_id',
        'scan_run_id',
        'scan_result_id',
        'candidate_watchlist_id',
        'spot_symbol_id',
        'coindcx_symbol',
        'event_type',
        'event_time',
        'event_price',
        'previous_price',
        'trigger_price',
        'actual_price_move_percent',
        'pnl_percent',
        'gain_percent',
        'max_gain_percent',
        'max_drawdown_percent',
        'previous_status',
        'new_status',
        'message',
        'notes',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'event_time' => 'datetime',
            'event_price' => 'decimal:12',
            'previous_price' => 'decimal:12',
            'trigger_price' => 'decimal:12',
            'actual_price_move_percent' => 'decimal:4',
            'pnl_percent' => 'decimal:4',
            'gain_percent' => 'decimal:4',
            'max_gain_percent' => 'decimal:4',
            'max_drawdown_percent' => 'decimal:4',
            'raw_payload' => 'array',
        ];
    }

    public function simulatedTrade(): BelongsTo { return $this->belongsTo(SimulatedTrade::class); }
    public function tradePlan(): BelongsTo { return $this->belongsTo(TradePlan::class); }
    public function scanRun(): BelongsTo { return $this->belongsTo(ScanRun::class); }
    public function scanResult(): BelongsTo { return $this->belongsTo(ScanResult::class); }
    public function candidateWatchlist(): BelongsTo { return $this->belongsTo(CandidateWatchlist::class); }
    public function spotSymbol(): BelongsTo { return $this->belongsTo(SpotSymbol::class); }
}
