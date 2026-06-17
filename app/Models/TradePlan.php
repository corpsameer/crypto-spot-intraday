<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TradePlan extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'valid_from' => 'datetime',
            'expires_at' => 'datetime',
            'triggered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'rejected_at' => 'datetime',
            'converted_at' => 'datetime',
            'raw_payload' => 'array',
            'score' => 'decimal:4',
            'reference_price' => 'decimal:12',
            'trigger_price' => 'decimal:12',
            'confirmation_price' => 'decimal:12',
            'entry_price' => 'decimal:12',
            'tp1_price' => 'decimal:12',
            'tp2_price' => 'decimal:12',
            'sl_price' => 'decimal:12',
            'trailing_start_price' => 'decimal:12',
            'tp1_percent' => 'decimal:4',
            'tp2_percent' => 'decimal:4',
            'sl_percent' => 'decimal:4',
            'risk_reward_ratio' => 'decimal:4',
            'latest_price' => 'decimal:12',
            'highest_price_seen' => 'decimal:12',
            'lowest_price_seen' => 'decimal:12',
            'max_plan_gain_percent' => 'decimal:4',
            'max_plan_drawdown_percent' => 'decimal:4',
        ];
    }

    public function scanRun(): BelongsTo { return $this->belongsTo(ScanRun::class); }
    public function scanResult(): BelongsTo { return $this->belongsTo(ScanResult::class); }
    public function candidateWatchlist(): BelongsTo { return $this->belongsTo(CandidateWatchlist::class); }
    public function spotSymbol(): BelongsTo { return $this->belongsTo(SpotSymbol::class); }
    public function simulatedTrade(): HasOne { return $this->hasOne(SimulatedTrade::class); }
    public function linkedSimulatedTrade(): BelongsTo { return $this->belongsTo(SimulatedTrade::class, 'simulated_trade_id'); }
    public function tradeEvents(): HasMany { return $this->hasMany(TradeEvent::class); }
}
