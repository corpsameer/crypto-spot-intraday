<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortfolioTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'portfolio_account_id',
        'trade_plan_id',
        'simulated_trade_id',
        'transaction_type',
        'direction',
        'amount',
        'balance_before',
        'balance_after',
        'reserved_before',
        'reserved_after',
        'deployed_before',
        'deployed_after',
        'realized_pnl_before',
        'realized_pnl_after',
        'description',
        'reference_type',
        'reference_id',
        'transaction_time',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'reserved_before' => 'decimal:2',
            'reserved_after' => 'decimal:2',
            'deployed_before' => 'decimal:2',
            'deployed_after' => 'decimal:2',
            'realized_pnl_before' => 'decimal:2',
            'realized_pnl_after' => 'decimal:2',
            'transaction_time' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    public function portfolioAccount(): BelongsTo { return $this->belongsTo(PortfolioAccount::class); }
    public function tradePlan(): BelongsTo { return $this->belongsTo(TradePlan::class); }
    public function simulatedTrade(): BelongsTo { return $this->belongsTo(SimulatedTrade::class); }
}
