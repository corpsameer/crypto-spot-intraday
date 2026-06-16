<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scanner_metrics', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('spot_symbol_id')->constrained('spot_symbols')->cascadeOnDelete();
            $table->string('coindcx_symbol')->index();
            $table->timestamp('metric_time')->index();
            $table->decimal('change_5m_percent', 12, 4)->nullable(); $table->decimal('change_15m_percent', 12, 4)->nullable(); $table->decimal('change_1h_percent', 12, 4)->nullable(); $table->decimal('change_4h_percent', 12, 4)->nullable(); $table->decimal('change_24h_percent', 12, 4)->nullable();
            $table->decimal('volume_spike_15m', 12, 4)->nullable(); $table->decimal('volume_spike_1h', 12, 4)->nullable(); $table->decimal('quote_volume_24h', 36, 12)->nullable(); $table->decimal('spread_percent', 12, 4)->nullable(); $table->decimal('bid_price', 30, 12)->nullable(); $table->decimal('ask_price', 30, 12)->nullable(); $table->decimal('orderbook_depth_usdt', 36, 12)->nullable(); $table->decimal('slippage_estimate_percent', 12, 4)->nullable();
            $table->decimal('distance_from_24h_high_percent', 12, 4)->nullable(); $table->decimal('candle_close_strength', 12, 4)->nullable(); $table->decimal('upper_wick_percent', 12, 4)->nullable(); $table->decimal('lower_wick_percent', 12, 4)->nullable();
            $table->decimal('relative_strength_vs_btc', 12, 4)->nullable(); $table->string('btc_context')->nullable(); $table->string('eth_context')->nullable(); $table->string('market_condition')->nullable();
            $table->decimal('overextension_risk', 12, 4)->nullable(); $table->decimal('risk_penalty', 12, 4)->nullable(); $table->decimal('final_score', 12, 4)->nullable()->index(); $table->string('score_label')->nullable();
            $table->boolean('passes_watchlist')->default(false)->index(); $table->boolean('passes_strong')->default(false)->index(); $table->text('rejection_reason')->nullable();
            $table->json('raw_payload')->nullable(); $table->timestamps();
            $table->index(['coindcx_symbol', 'metric_time']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('scanner_metrics');
    }
};
