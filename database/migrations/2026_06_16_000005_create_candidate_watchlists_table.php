<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_watchlists', function (Blueprint $table): void {
            $table->id(); $table->foreignId('spot_symbol_id')->constrained('spot_symbols')->cascadeOnDelete(); $table->foreignId('scanner_metric_id')->nullable()->constrained('scanner_metrics')->nullOnDelete();
            $table->string('coindcx_symbol', 32)->index(); $table->timestamp('detected_at')->index(); $table->string('candidate_type', 32)->default('watchlist')->index(); $table->string('entry_strategy', 32)->nullable();
            $table->decimal('trigger_price', 30, 12)->nullable(); $table->decimal('confirmation_price', 30, 12)->nullable(); $table->decimal('last_price', 30, 12)->nullable(); $table->decimal('score', 12, 4)->nullable()->index();
            $table->string('status', 32)->default('open')->index(); $table->text('reason')->nullable(); $table->text('rejection_reason')->nullable(); $table->timestamp('expires_at')->nullable(); $table->json('raw_payload')->nullable(); $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_watchlists');
    }
};
