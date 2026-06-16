<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('run_uuid')->nullable()->unique();
            $table->string('scan_type', 32)->default('manual')->index();
            $table->string('scan_name')->nullable();
            $table->string('status', 32)->default('pending')->index();
            $table->timestamp('scheduled_for')->nullable()->index();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->integer('duration_seconds')->nullable();
            $table->integer('total_active_symbols')->default(0);
            $table->integer('ticker_rows_fetched')->default(0);
            $table->integer('prefilter_passed_count')->default(0);
            $table->integer('candles_fetched_count')->default(0);
            $table->integer('metrics_calculated_count')->default(0);
            $table->integer('scored_count')->default(0);
            $table->integer('watchlist_created_count')->default(0);
            $table->integer('trade_plans_created_count')->default(0);
            $table->string('quote_filter', 16)->nullable()->index();
            $table->decimal('min_quote_volume_24h', 36, 12)->nullable();
            $table->decimal('min_change_15m_percent', 12, 4)->nullable();
            $table->decimal('min_change_1h_percent', 12, 4)->nullable();
            $table->decimal('min_volume_spike_15m', 12, 4)->nullable();
            $table->decimal('min_score', 12, 4)->nullable();
            $table->json('settings_snapshot')->nullable();
            $table->decimal('top_score', 12, 4)->nullable();
            $table->string('top_symbol', 32)->nullable()->index();
            $table->text('error_message')->nullable();
            $table->text('notes')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_runs');
    }
};
