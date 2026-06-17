<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ScanResult extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'prefilter_passed' => 'boolean',
            'score_passed' => 'boolean',
            'selected_for_watchlist' => 'boolean',
            'candidate_created' => 'boolean',
            'trade_plan_created' => 'boolean',
            'suggested_expiry_at' => 'datetime',
            'evaluated_at' => 'datetime',
            'raw_payload' => 'array',
            'selection_rank' => 'integer',
            'score_breakdown' => 'array',
            'last_price' => 'decimal:12',
            'change_24h_percent' => 'decimal:4',
            'quote_volume_24h' => 'decimal:12',
            'change_5m_percent' => 'decimal:4',
            'change_15m_percent' => 'decimal:4',
            'change_1h_percent' => 'decimal:4',
            'change_4h_percent' => 'decimal:4',
            'volume_spike_15m' => 'decimal:4',
            'volume_spike_1h' => 'decimal:4',
            'spread_percent' => 'decimal:4',
            'orderbook_depth_usdt' => 'decimal:12',
            'slippage_estimate_percent' => 'decimal:4',
            'distance_from_24h_high_percent' => 'decimal:4',
            'candle_close_strength' => 'decimal:4',
            'upper_wick_percent' => 'decimal:4',
            'lower_wick_percent' => 'decimal:4',
            'relative_strength_vs_btc' => 'decimal:4',
            'overextension_risk' => 'decimal:4',
            'risk_penalty' => 'decimal:4',
            'final_score' => 'decimal:4',
            'suggested_entry_price' => 'decimal:12',
            'suggested_trigger_price' => 'decimal:12',
            'suggested_tp1_price' => 'decimal:12',
            'suggested_tp2_price' => 'decimal:12',
            'suggested_sl_price' => 'decimal:12',
        ];
    }

    public function scanRun(): BelongsTo { return $this->belongsTo(ScanRun::class); }
    public function spotSymbol(): BelongsTo { return $this->belongsTo(SpotSymbol::class); }
    public function scannerMetric(): BelongsTo { return $this->belongsTo(ScannerMetric::class); }
    public function candidateWatchlist(): BelongsTo { return $this->belongsTo(CandidateWatchlist::class); }
    public function tradePlan(): BelongsTo { return $this->belongsTo(TradePlan::class); }
    public function simulatedTrade(): HasOne { return $this->hasOne(SimulatedTrade::class); }
    public function tradeEvents(): HasMany { return $this->hasMany(TradeEvent::class); }
}
