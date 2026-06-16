<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MissedGainer extends Model
{
    use HasFactory;

    protected $fillable = [
        'spot_symbol_id',
        'coindcx_symbol',
        'detected_at',
        'analysis_window_start',
        'analysis_window_end',
        'start_price',
        'peak_price',
        'end_price',
        'max_gain_percent',
        'scanner_detected',
        'first_scanner_detected_at',
        'first_scanner_score',
        'simulated_trade_created',
        'reason_missed',
        'notes',
        'raw_payload'
    ];

    protected function casts(): array
    {
        return [
            'detected_at' => 'datetime',
            'analysis_window_start' => 'datetime',
            'analysis_window_end' => 'datetime',
            'first_scanner_detected_at' => 'datetime',
            'start_price' => 'decimal:12',
            'peak_price' => 'decimal:12',
            'end_price' => 'decimal:12',
            'max_gain_percent' => 'decimal:4',
            'first_scanner_score' => 'decimal:4',
            'scanner_detected' => 'boolean',
            'simulated_trade_created' => 'boolean',
            'raw_payload' => 'array'
        ];
    }


    public function spotSymbol(): BelongsTo { return $this->belongsTo(SpotSymbol::class); }

}
