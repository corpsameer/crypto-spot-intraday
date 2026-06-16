<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TradeEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'simulated_trade_id',
        'spot_symbol_id',
        'coindcx_symbol',
        'event_type',
        'event_time',
        'event_price',
        'gain_percent',
        'notes',
        'raw_payload'
    ];

    protected function casts(): array
    {
        return [
            'event_time' => 'datetime',
            'event_price' => 'decimal:12',
            'gain_percent' => 'decimal:4',
            'raw_payload' => 'array'
        ];
    }


    public function simulatedTrade(): BelongsTo { return $this->belongsTo(SimulatedTrade::class); }
    public function spotSymbol(): BelongsTo { return $this->belongsTo(SpotSymbol::class); }

}
