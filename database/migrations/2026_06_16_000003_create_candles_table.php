<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('spot_symbol_id')->constrained('spot_symbols')->cascadeOnDelete();
            $table->string('coindcx_symbol', 32)->index();
            $table->string('timeframe', 16)->index();
            $table->timestamp('candle_time')->index();
            $table->decimal('open', 30, 12);
            $table->decimal('high', 30, 12);
            $table->decimal('low', 30, 12);
            $table->decimal('close', 30, 12);
            $table->decimal('volume', 36, 12)->nullable();
            $table->decimal('quote_volume', 36, 12)->nullable();
            $table->unsignedInteger('trade_count')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['spot_symbol_id', 'timeframe', 'candle_time']);
            $table->index(['coindcx_symbol', 'timeframe', 'candle_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candles');
    }
};
