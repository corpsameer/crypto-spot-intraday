<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyGainerLeaderboard extends Model
{
    use HasFactory;

    protected $table = 'daily_gainer_leaderboard';

    protected $fillable = [
        'leaderboard_date','run_time','source','quote_filter','rank','spot_symbol_id','coindcx_symbol','api_pair','base_asset','quote_asset','last_price','open_price_24h','high_price_24h','low_price_24h','change_24h_percent','abs_change_24h_percent','volume_24h','quote_volume_24h','bid_price','ask_price','spread_percent','is_top_gainer','is_top_loser','matched_in_scan','selected_for_watchlist','trade_plan_created','simulated_trade_created','best_scan_run_id','best_scan_result_id','best_final_score','best_score_label','notes','raw_payload'
    ];

    protected function casts(): array
    {
        return [
            'leaderboard_date' => 'date', 'run_time' => 'datetime',
            'is_top_gainer' => 'boolean', 'is_top_loser' => 'boolean', 'matched_in_scan' => 'boolean', 'selected_for_watchlist' => 'boolean', 'trade_plan_created' => 'boolean', 'simulated_trade_created' => 'boolean', 'raw_payload' => 'array',
            'last_price' => 'decimal:12', 'open_price_24h' => 'decimal:12', 'high_price_24h' => 'decimal:12', 'low_price_24h' => 'decimal:12', 'bid_price' => 'decimal:12', 'ask_price' => 'decimal:12',
            'change_24h_percent' => 'decimal:4', 'abs_change_24h_percent' => 'decimal:4', 'spread_percent' => 'decimal:4', 'best_final_score' => 'decimal:4',
            'volume_24h' => 'decimal:8', 'quote_volume_24h' => 'decimal:8',
        ];
    }

    public function spotSymbol(): BelongsTo { return $this->belongsTo(SpotSymbol::class); }
    public function bestScanRun(): BelongsTo { return $this->belongsTo(ScanRun::class, 'best_scan_run_id'); }
    public function bestScanResult(): BelongsTo { return $this->belongsTo(ScanResult::class, 'best_scan_result_id'); }
}
