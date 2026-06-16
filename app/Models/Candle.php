<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Candle extends Model
{
    use HasFactory;

    protected $fillable = [
        'spot_symbol_id',
        'coindcx_symbol',
        'timeframe',
        'candle_time',
        'open',
        'high',
        'low',
        'close',
        'volume',
        'quote_volume',
        'trade_count',
        'raw_payload'
    ];

    protected function casts(): array
    {
        return [
            'candle_time' => 'datetime',
            'open' => 'decimal:12',
            'high' => 'decimal:12',
            'low' => 'decimal:12',
            'close' => 'decimal:12',
            'volume' => 'decimal:12',
            'quote_volume' => 'decimal:12',
            'raw_payload' => 'array'
        ];
    }


    public function spotSymbol(): BelongsTo { return $this->belongsTo(SpotSymbol::class); }

}
