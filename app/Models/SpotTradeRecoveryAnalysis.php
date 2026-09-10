<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpotTradeRecoveryAnalysis extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        $casts = ['entry_time' => 'datetime', 'sl_time' => 'datetime', 'post_sl_tp1_hit_at' => 'datetime',
            'post_sl_tp2_hit_at' => 'datetime', 'post_sl_highest_price_at' => 'datetime',
            'post_sl_lowest_price_at' => 'datetime', 'analyzed_through' => 'datetime',
            'post_sl_tp1_hit' => 'boolean', 'post_sl_tp2_hit' => 'boolean', 'strategy_results' => 'array'];
        foreach ([7, 15, 30, 60] as $days) foreach (["tp1_within_{$days}d", "tp2_within_{$days}d", "observation_{$days}d_complete"] as $key) $casts[$key] = 'boolean';
        return $casts;
    }

    public function simulatedTrade(): BelongsTo { return $this->belongsTo(SimulatedTrade::class); }
}
