<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ScannerMetric extends Model
{
    use HasFactory;

    protected $fillable = [
        'spot_symbol_id',
        'coindcx_symbol',
        'metric_time',
        'change_5m_percent',
        'change_15m_percent',
        'change_1h_percent',
        'change_4h_percent',
        'change_24h_percent',
        'volume_spike_15m',
        'volume_spike_1h',
        'quote_volume_24h',
        'spread_percent',
        'bid_price',
        'ask_price',
        'orderbook_depth_usdt',
        'slippage_estimate_percent',
        'distance_from_24h_high_percent',
        'candle_close_strength',
        'upper_wick_percent',
        'lower_wick_percent',
        'relative_strength_vs_btc',
        'btc_context',
        'eth_context',
        'market_condition',
        'overextension_risk',
        'risk_penalty',
        'final_score',
        'score_label',
        'passes_watchlist',
        'passes_strong',
        'rejection_reason',
        'raw_payload'
    ];

    protected function casts(): array
    {
        return [
            'metric_time' => 'datetime',
            'passes_watchlist' => 'boolean',
            'passes_strong' => 'boolean',
            'raw_payload' => 'array',
            'change_5m_percent' => 'decimal:4',
            'change_15m_percent' => 'decimal:4',
            'change_1h_percent' => 'decimal:4',
            'change_4h_percent' => 'decimal:4',
            'change_24h_percent' => 'decimal:4',
            'volume_spike_15m' => 'decimal:4',
            'volume_spike_1h' => 'decimal:4',
            'quote_volume_24h' => 'decimal:4',
            'spread_percent' => 'decimal:4',
            'bid_price' => 'decimal:4',
            'ask_price' => 'decimal:4',
            'orderbook_depth_usdt' => 'decimal:4',
            'slippage_estimate_percent' => 'decimal:4',
            'distance_from_24h_high_percent' => 'decimal:4',
            'candle_close_strength' => 'decimal:4',
            'upper_wick_percent' => 'decimal:4',
            'lower_wick_percent' => 'decimal:4',
            'relative_strength_vs_btc' => 'decimal:4',
            'overextension_risk' => 'decimal:4',
            'risk_penalty' => 'decimal:4',
            'final_score' => 'decimal:4'
        ];
    }


    public function spotSymbol(): BelongsTo { return $this->belongsTo(SpotSymbol::class); }
    public function scanResults(): HasMany { return $this->hasMany(ScanResult::class); }

}
