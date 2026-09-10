<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spot_trade_recovery_analyses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('simulated_trade_id')->unique()->constrained('simulated_trades')->cascadeOnDelete();
            $table->string('symbol', 32); $table->string('api_pair', 64)->index();
            $table->timestamp('entry_time'); $table->decimal('entry_price', 30, 12);
            $table->timestamp('sl_time'); $table->decimal('sl_price', 30, 12);
            $table->decimal('tp1_price', 30, 12); $table->decimal('tp2_price', 30, 12)->nullable();
            $table->boolean('post_sl_tp1_hit')->default(false); $table->timestamp('post_sl_tp1_hit_at')->nullable();
            $table->boolean('post_sl_tp2_hit')->default(false); $table->timestamp('post_sl_tp2_hit_at')->nullable();
            $table->decimal('hours_from_entry_to_tp1', 14, 4)->nullable(); $table->decimal('days_from_entry_to_tp1', 14, 4)->nullable();
            $table->decimal('hours_from_sl_to_tp1', 14, 4)->nullable(); $table->decimal('days_from_sl_to_tp1', 14, 4)->nullable();
            $table->decimal('post_sl_highest_price', 30, 12)->nullable(); $table->timestamp('post_sl_highest_price_at')->nullable();
            $table->decimal('post_sl_max_gain_percent', 14, 6)->nullable();
            $table->decimal('post_sl_lowest_price', 30, 12)->nullable(); $table->timestamp('post_sl_lowest_price_at')->nullable();
            $table->decimal('post_sl_max_drawdown_percent', 14, 6)->nullable();
            $table->decimal('mae_before_tp1_percent', 14, 6)->nullable();
            $table->decimal('sl_distance_percent', 14, 6)->nullable();
            $table->decimal('extra_drawdown_beyond_sl_percent', 14, 6)->nullable();
            foreach ([7, 15, 30, 60] as $days) {
                $table->boolean("tp1_within_{$days}d")->nullable();
                $table->boolean("tp2_within_{$days}d")->nullable();
                $table->boolean("observation_{$days}d_complete")->default(false);
            }
            $table->timestamp('analyzed_through')->nullable();
            $table->string('data_quality_status', 32)->default('complete')->index();
            $table->text('data_quality_notes')->nullable();
            $table->json('strategy_results')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void { Schema::dropIfExists('spot_trade_recovery_analyses'); }
};
