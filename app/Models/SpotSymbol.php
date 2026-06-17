<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SpotSymbol extends Model
{
    use HasFactory;

    protected $fillable = [
        'coindcx_symbol',
        'api_pair',
        'base_asset',
        'quote_asset',
        'display_name',
        'status',
        'is_active',
        'min_price',
        'max_price',
        'min_quantity',
        'quantity_precision',
        'price_precision',
        'tick_size',
        'step_size',
        'last_synced_at',
        'raw_payload'
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'min_price' => 'decimal:12',
            'max_price' => 'decimal:12',
            'min_quantity' => 'decimal:12',
            'tick_size' => 'decimal:12',
            'step_size' => 'decimal:12',
            'last_synced_at' => 'datetime',
            'raw_payload' => 'array'
        ];
    }


    public function candles(): HasMany { return $this->hasMany(Candle::class); }
    public function scannerMetrics(): HasMany { return $this->hasMany(ScannerMetric::class); }
    public function candidateWatchlists(): HasMany { return $this->hasMany(CandidateWatchlist::class); }
    public function scanResults(): HasMany { return $this->hasMany(ScanResult::class); }
    public function tradePlans(): HasMany { return $this->hasMany(TradePlan::class); }
    public function simulatedTrades(): HasMany { return $this->hasMany(SimulatedTrade::class); }
    public function tradeEvents(): HasMany { return $this->hasMany(TradeEvent::class); }
    public function missedGainers(): HasMany { return $this->hasMany(MissedGainer::class); }
    public function dailyGainerLeaderboard(): HasMany { return $this->hasMany(DailyGainerLeaderboard::class); }

}
