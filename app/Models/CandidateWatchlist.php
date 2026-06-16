<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CandidateWatchlist extends Model
{
    use HasFactory;

    protected $fillable = [
        'spot_symbol_id',
        'scanner_metric_id',
        'coindcx_symbol',
        'detected_at',
        'candidate_type',
        'entry_strategy',
        'trigger_price',
        'confirmation_price',
        'last_price',
        'score',
        'status',
        'reason',
        'rejection_reason',
        'expires_at',
        'raw_payload'
    ];

    protected function casts(): array
    {
        return [
            'detected_at' => 'datetime',
            'expires_at' => 'datetime',
            'trigger_price' => 'decimal:12',
            'confirmation_price' => 'decimal:12',
            'last_price' => 'decimal:12',
            'score' => 'decimal:4',
            'raw_payload' => 'array'
        ];
    }


    public function spotSymbol(): BelongsTo { return $this->belongsTo(SpotSymbol::class); }
    public function scannerMetric(): BelongsTo { return $this->belongsTo(ScannerMetric::class); }
    public function simulatedTrade(): HasOne { return $this->hasOne(SimulatedTrade::class); }

}
