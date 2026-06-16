<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simulated_trades', function (Blueprint $table): void {
            $table->id(); $table->foreignId('spot_symbol_id')->constrained('spot_symbols')->cascadeOnDelete(); $table->foreignId('candidate_watchlist_id')->nullable()->constrained('candidate_watchlists')->nullOnDelete();
            $table->string('coindcx_symbol')->index(); $table->string('entry_strategy')->index(); $table->string('status')->default('pending')->index();
            $table->decimal('entry_price',30,12)->nullable(); $table->decimal('entry_trigger_price',30,12)->nullable(); $table->timestamp('entry_triggered_at')->nullable()->index(); $table->decimal('quantity',36,12)->nullable(); $table->decimal('notional_usdt',36,12)->nullable();
            $table->decimal('tp1_price',30,12)->nullable(); $table->decimal('tp2_price',30,12)->nullable(); $table->decimal('sl_price',30,12)->nullable(); $table->decimal('trailing_stop_price',30,12)->nullable(); $table->boolean('trailing_active')->default(false);
            $table->decimal('highest_price',30,12)->nullable(); $table->decimal('lowest_price',30,12)->nullable(); $table->decimal('max_gain_percent',12,4)->nullable(); $table->decimal('max_drawdown_percent',12,4)->nullable(); $table->decimal('current_gain_percent',12,4)->nullable(); $table->decimal('final_pnl_percent',12,4)->nullable();
            $table->timestamp('tp1_hit_at')->nullable(); $table->timestamp('tp2_hit_at')->nullable(); $table->timestamp('sl_hit_at')->nullable(); $table->timestamp('trailing_activated_at')->nullable(); $table->timestamp('closed_at')->nullable()->index(); $table->timestamp('expires_at')->nullable()->index();
            $table->decimal('exit_price',30,12)->nullable(); $table->string('exit_reason')->nullable(); $table->text('notes')->nullable(); $table->json('raw_payload')->nullable(); $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('simulated_trades');
    }
};
