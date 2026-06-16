<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('missed_gainers', function (Blueprint $table): void {
            $table->id(); $table->foreignId('spot_symbol_id')->constrained('spot_symbols')->cascadeOnDelete(); $table->string('coindcx_symbol')->index(); $table->timestamp('detected_at')->index();
            $table->timestamp('analysis_window_start')->nullable(); $table->timestamp('analysis_window_end')->nullable(); $table->decimal('start_price',30,12)->nullable(); $table->decimal('peak_price',30,12)->nullable(); $table->decimal('end_price',30,12)->nullable(); $table->decimal('max_gain_percent',12,4)->nullable()->index();
            $table->boolean('scanner_detected')->default(false)->index(); $table->timestamp('first_scanner_detected_at')->nullable(); $table->decimal('first_scanner_score',12,4)->nullable(); $table->boolean('simulated_trade_created')->default(false)->index();
            $table->text('reason_missed')->nullable(); $table->text('notes')->nullable(); $table->json('raw_payload')->nullable(); $table->timestamps();
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('missed_gainers');
    }
};
