<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_events', function (Blueprint $table): void {
            $table->id(); $table->foreignId('simulated_trade_id')->constrained('simulated_trades')->cascadeOnDelete(); $table->foreignId('spot_symbol_id')->nullable()->constrained('spot_symbols')->nullOnDelete();
            $table->string('coindcx_symbol')->index(); $table->string('event_type')->index(); $table->timestamp('event_time')->index(); $table->decimal('event_price',30,12)->nullable(); $table->decimal('gain_percent',12,4)->nullable(); $table->text('notes')->nullable(); $table->json('raw_payload')->nullable(); $table->timestamps();
            $table->index(['simulated_trade_id','event_type']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('trade_events');
    }
};
