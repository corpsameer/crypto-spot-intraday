<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('scan_run_id')->nullable()->constrained('scan_runs')->nullOnDelete();
            $table->foreignId('scan_result_id')->nullable()->constrained('scan_results')->nullOnDelete();
            $table->foreignId('candidate_watchlist_id')->nullable()->constrained('candidate_watchlists')->nullOnDelete();
            $table->foreignId('spot_symbol_id')->constrained('spot_symbols')->cascadeOnDelete();
            $table->foreignId('simulated_trade_id')->nullable()->constrained('simulated_trades')->nullOnDelete();
            $table->string('coindcx_symbol', 32)->index();
            $table->string('api_pair', 64)->nullable();
            $table->string('base_asset', 32)->nullable();
            $table->string('quote_asset', 32)->nullable()->index();
            $table->string('plan_type', 32)->default('scanner')->index();
            $table->string('entry_strategy', 32)->default('breakout')->index();
            $table->string('status', 32)->default('pending')->index();
            $table->decimal('score', 12, 4)->nullable()->index();
            $table->string('score_label', 32)->nullable();
            $table->decimal('reference_price', 30, 12)->nullable();
            $table->decimal('trigger_price', 30, 12)->nullable();
            $table->decimal('confirmation_price', 30, 12)->nullable();
            $table->decimal('entry_price', 30, 12)->nullable();
            $table->string('entry_condition', 64)->nullable();
            $table->decimal('tp1_price', 30, 12)->nullable();
            $table->decimal('tp2_price', 30, 12)->nullable();
            $table->decimal('sl_price', 30, 12)->nullable();
            $table->decimal('trailing_start_price', 30, 12)->nullable();
            $table->decimal('tp1_percent', 12, 4)->nullable();
            $table->decimal('tp2_percent', 12, 4)->nullable();
            $table->decimal('sl_percent', 12, 4)->nullable();
            $table->decimal('risk_reward_ratio', 12, 4)->nullable();
            $table->timestamp('valid_from')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('triggered_at')->nullable()->index();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('converted_at')->nullable()->index();
            $table->decimal('latest_price', 30, 12)->nullable();
            $table->decimal('highest_price_seen', 30, 12)->nullable();
            $table->decimal('lowest_price_seen', 30, 12)->nullable();
            $table->decimal('max_plan_gain_percent', 12, 4)->nullable();
            $table->decimal('max_plan_drawdown_percent', 12, 4)->nullable();
            $table->text('plan_reason')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
            $table->index(['status', 'expires_at']);
            $table->index(['coindcx_symbol', 'status']);
            $table->index(['scan_run_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_plans');
    }
};
