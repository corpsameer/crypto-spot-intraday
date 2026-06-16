<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scan_run_id')->constrained('scan_runs')->cascadeOnDelete();
            $table->foreignId('spot_symbol_id')->nullable()->constrained('spot_symbols')->nullOnDelete();
            $table->foreignId('scanner_metric_id')->nullable()->constrained('scanner_metrics')->nullOnDelete();
            $table->foreignId('candidate_watchlist_id')->nullable()->constrained('candidate_watchlists')->nullOnDelete();
            $table->unsignedBigInteger('trade_plan_id')->nullable()->index();
            $table->string('coindcx_symbol', 32)->index();
            $table->string('api_pair', 64)->nullable();
            $table->string('base_asset', 32)->nullable();
            $table->string('quote_asset', 32)->nullable()->index();
            $table->string('status', 32)->default('discovered')->index();
            $table->string('stage', 32)->nullable()->index();
            $table->boolean('prefilter_passed')->default(false)->index();
            $table->boolean('score_passed')->default(false)->index();
            $table->boolean('candidate_created')->default(false)->index();
            $table->boolean('trade_plan_created')->default(false)->index();
            $table->decimal('last_price', 30, 12)->nullable();
            $table->decimal('change_24h_percent', 12, 4)->nullable();
            $table->decimal('quote_volume_24h', 36, 12)->nullable();
            $table->text('prefilter_reason')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->decimal('change_5m_percent', 12, 4)->nullable();
            $table->decimal('change_15m_percent', 12, 4)->nullable();
            $table->decimal('change_1h_percent', 12, 4)->nullable();
            $table->decimal('change_4h_percent', 12, 4)->nullable();
            $table->decimal('volume_spike_15m', 12, 4)->nullable();
            $table->decimal('volume_spike_1h', 12, 4)->nullable();
            $table->decimal('spread_percent', 12, 4)->nullable();
            $table->decimal('orderbook_depth_usdt', 36, 12)->nullable();
            $table->decimal('slippage_estimate_percent', 12, 4)->nullable();
            $table->decimal('distance_from_24h_high_percent', 12, 4)->nullable();
            $table->decimal('candle_close_strength', 12, 4)->nullable();
            $table->decimal('upper_wick_percent', 12, 4)->nullable();
            $table->decimal('lower_wick_percent', 12, 4)->nullable();
            $table->decimal('relative_strength_vs_btc', 12, 4)->nullable();
            $table->decimal('overextension_risk', 12, 4)->nullable();
            $table->decimal('risk_penalty', 12, 4)->nullable();
            $table->decimal('final_score', 12, 4)->nullable()->index();
            $table->string('score_label', 32)->nullable();
            $table->json('score_breakdown')->nullable();
            $table->string('suggested_entry_strategy', 32)->nullable();
            $table->decimal('suggested_entry_price', 30, 12)->nullable();
            $table->decimal('suggested_trigger_price', 30, 12)->nullable();
            $table->decimal('suggested_tp1_price', 30, 12)->nullable();
            $table->decimal('suggested_tp2_price', 30, 12)->nullable();
            $table->decimal('suggested_sl_price', 30, 12)->nullable();
            $table->timestamp('suggested_expiry_at')->nullable();
            $table->timestamp('evaluated_at')->nullable()->index();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
            $table->index(['scan_run_id', 'coindcx_symbol']);
            $table->index(['scan_run_id', 'final_score']);
            $table->index(['scan_run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_results');
    }
};
