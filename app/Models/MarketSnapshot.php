<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MarketSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'snapshot_time',
        'btc_price',
        'eth_price',
        'btc_change_5m_percent',
        'btc_change_15m_percent',
        'btc_change_1h_percent',
        'btc_change_4h_percent',
        'btc_change_24h_percent',
        'eth_change_5m_percent',
        'eth_change_15m_percent',
        'eth_change_1h_percent',
        'eth_change_4h_percent',
        'eth_change_24h_percent',
        'market_condition',
        'notes',
        'raw_payload'
    ];

    protected function casts(): array
    {
        return [
            'snapshot_time' => 'datetime',
            'btc_price' => 'decimal:12',
            'eth_price' => 'decimal:12',
            'raw_payload' => 'array'
        ];
    }

}
