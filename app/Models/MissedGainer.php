<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MissedGainer extends Model
{
    use HasFactory;

    protected $fillable = [
        'analysis_date','leaderboard_id','leaderboard_rank','spot_symbol_id','coindcx_symbol','api_pair','base_asset','quote_asset','actual_change_24h_percent','actual_last_price','actual_quote_volume_24h','actual_spread_percent','matched_in_scan','selected_for_watchlist','trade_plan_created','simulated_trade_created','entry_triggered','tp1_hit','tp2_hit','sl_hit','trailing_hit','expired','best_scan_run_id','best_scan_result_id','best_candidate_watchlist_id','best_trade_plan_id','best_simulated_trade_id','best_final_score','best_score_label','best_rank','selected_rank','miss_type','miss_reason','miss_severity','action_needed','notes','prefilter_passed','score_passed','fallback_selected','rejection_reason','setup_type','entry_strategy','planned_entry_price','trigger_price','tp1_price','tp2_price','sl_price','latest_trade_status','current_pnl_percent','max_gain_percent','final_pnl_percent','raw_payload','analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'analysis_date' => 'date', 'analyzed_at' => 'datetime', 'raw_payload' => 'array',
            'matched_in_scan' => 'boolean', 'selected_for_watchlist' => 'boolean', 'trade_plan_created' => 'boolean', 'simulated_trade_created' => 'boolean', 'entry_triggered' => 'boolean', 'tp1_hit' => 'boolean', 'tp2_hit' => 'boolean', 'sl_hit' => 'boolean', 'trailing_hit' => 'boolean', 'expired' => 'boolean',
            'prefilter_passed' => 'boolean', 'score_passed' => 'boolean', 'fallback_selected' => 'boolean',
            'actual_change_24h_percent' => 'decimal:4', 'actual_spread_percent' => 'decimal:4', 'best_final_score' => 'decimal:4', 'current_pnl_percent' => 'decimal:4', 'max_gain_percent' => 'decimal:4', 'final_pnl_percent' => 'decimal:4',
            'actual_last_price' => 'decimal:12', 'planned_entry_price' => 'decimal:12', 'trigger_price' => 'decimal:12', 'tp1_price' => 'decimal:12', 'tp2_price' => 'decimal:12', 'sl_price' => 'decimal:12',
            'actual_quote_volume_24h' => 'decimal:8',
        ];
    }

    public function leaderboard(): BelongsTo { return $this->belongsTo(DailyGainerLeaderboard::class, 'leaderboard_id'); }
    public function spotSymbol(): BelongsTo { return $this->belongsTo(SpotSymbol::class); }
    public function bestScanRun(): BelongsTo { return $this->belongsTo(ScanRun::class, 'best_scan_run_id'); }
    public function bestScanResult(): BelongsTo { return $this->belongsTo(ScanResult::class, 'best_scan_result_id'); }
    public function bestCandidateWatchlist(): BelongsTo { return $this->belongsTo(CandidateWatchlist::class, 'best_candidate_watchlist_id'); }
    public function bestTradePlan(): BelongsTo { return $this->belongsTo(TradePlan::class, 'best_trade_plan_id'); }
    public function bestSimulatedTrade(): BelongsTo { return $this->belongsTo(SimulatedTrade::class, 'best_simulated_trade_id'); }
}
