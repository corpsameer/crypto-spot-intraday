<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PortfolioAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'currency',
        'starting_capital',
        'current_cash',
        'reserved_cash',
        'deployed_capital',
        'realized_pnl',
        'unrealized_pnl',
        'total_equity',
        'total_return_percent',
        'max_open_trades',
        'preferred_open_trades',
        'max_pending_trade_plans',
        'reserve_cash_percent',
        'min_trade_capital',
        'max_trade_capital',
        'is_active',
        'notes',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'starting_capital' => 'decimal:2',
            'current_cash' => 'decimal:2',
            'reserved_cash' => 'decimal:2',
            'deployed_capital' => 'decimal:2',
            'realized_pnl' => 'decimal:2',
            'unrealized_pnl' => 'decimal:2',
            'total_equity' => 'decimal:2',
            'total_return_percent' => 'decimal:4',
            'reserve_cash_percent' => 'decimal:4',
            'min_trade_capital' => 'decimal:2',
            'max_trade_capital' => 'decimal:2',
            'is_active' => 'boolean',
            'raw_payload' => 'array',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function transactions(): HasMany { return $this->hasMany(PortfolioTransaction::class); }
    public function tradePlans(): HasMany { return $this->hasMany(TradePlan::class); }
    public function simulatedTrades(): HasMany { return $this->hasMany(SimulatedTrade::class); }
}
