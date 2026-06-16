<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spot_symbols', function (Blueprint $table): void {
            $table->id();
            $table->string('coindcx_symbol', 32)->unique();
            $table->string('base_asset', 32)->nullable()->index();
            $table->string('quote_asset', 32)->nullable()->index();
            $table->string('display_name', 64)->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->decimal('min_price', 30, 12)->nullable();
            $table->decimal('max_price', 30, 12)->nullable();
            $table->decimal('min_quantity', 36, 12)->nullable();
            $table->unsignedInteger('quantity_precision')->nullable();
            $table->unsignedInteger('price_precision')->nullable();
            $table->decimal('tick_size', 30, 12)->nullable();
            $table->decimal('step_size', 36, 12)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spot_symbols');
    }
};
