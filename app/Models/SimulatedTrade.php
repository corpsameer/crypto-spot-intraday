<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SimulatedTrade extends Model
{
    use HasFactory;

    protected $fillable = [
        'spot_symbol_id',
        'candidate_watchlist_id',
        'coindcx_symbol',
        'entry_strategy',
        'status',
        'entry_price',
        'entry_trigger_price',
        'entry_triggered_at',
        'quantity',
        'notional_usdt',
        'tp1_price',
        'tp2_price',
        'sl_price',
        'trailing_stop_price',
        'trailing_active',
        'highest_price',
        'lowest_price',
        'max_gain_percent',
        'max_drawdown_percent',
        'current_gain_percent',
        'final_pnl_percent',
        'tp1_hit_at',
        'tp2_hit_at',
        'sl_hit_at',
        'trailing_activated_at',
        'closed_at',
        'expires_at',
        'exit_price',
        'exit_reason',
        'notes',
        'raw_payload'
    ];

    protected function casts(): array
    {
        return [
            'trailing_active' => 'boolean',
            'raw_payload' => 'array',
            'entry_triggered_at' => 'datetime',
            'tp1_hit_at' => 'datetime',
            'tp2_hit_at' => 'datetime',
            'sl_hit_at' => 'datetime',
            'trailing_activated_at' => 'datetime',
            'closed_at' => 'datetime',
            'expires_at' => 'datetime',
            'entry_price' => 'decimal:12',
            'entry_trigger_price' => 'decimal:12',
            'quantity' => 'decimal:12',
            'notional_usdt' => 'decimal:12',
            'tp1_price' => 'decimal:12',
            'tp2_price' => 'decimal:12',
            'sl_price' => 'decimal:12',
            'trailing_stop_price' => 'decimal:12',
            'highest_price' => 'decimal:12',
            'lowest_price' => 'decimal:12',
            'exit_price' => 'decimal:12',
            'max_gain_percent' => 'decimal:4',
            'max_drawdown_percent' => 'decimal:4',
            'current_gain_percent' => 'decimal:4',
            'final_pnl_percent' => 'decimal:4'
        ];
    }


    public function spotSymbol(): BelongsTo { return $this->belongsTo(SpotSymbol::class); }
    public function candidateWatchlist(): BelongsTo { return $this->belongsTo(CandidateWatchlist::class); }
    public function tradeEvents(): HasMany { return $this->hasMany(TradeEvent::class); }

}
