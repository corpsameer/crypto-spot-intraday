<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScanRun extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'settings_snapshot' => 'array',
            'raw_payload' => 'array',
            'min_quote_volume_24h' => 'decimal:12',
            'min_change_15m_percent' => 'decimal:4',
            'min_change_1h_percent' => 'decimal:4',
            'min_volume_spike_15m' => 'decimal:4',
            'min_score' => 'decimal:4',
            'top_score' => 'decimal:4',
        ];
    }

    public function scanResults(): HasMany { return $this->hasMany(ScanResult::class); }
    public function tradePlans(): HasMany { return $this->hasMany(TradePlan::class); }
    public function simulatedTrades(): HasMany { return $this->hasMany(SimulatedTrade::class); }
    public function tradeEvents(): HasMany { return $this->hasMany(TradeEvent::class); }
}
