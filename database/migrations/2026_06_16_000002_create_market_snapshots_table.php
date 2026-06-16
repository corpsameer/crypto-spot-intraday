<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('snapshot_time')->index();
            $table->decimal('btc_price', 30, 12)->nullable();
            $table->decimal('eth_price', 30, 12)->nullable();
            $table->decimal('btc_change_5m_percent', 12, 4)->nullable();
            $table->decimal('btc_change_15m_percent', 12, 4)->nullable();
            $table->decimal('btc_change_1h_percent', 12, 4)->nullable();
            $table->decimal('btc_change_4h_percent', 12, 4)->nullable();
            $table->decimal('btc_change_24h_percent', 12, 4)->nullable();
            $table->decimal('eth_change_5m_percent', 12, 4)->nullable();
            $table->decimal('eth_change_15m_percent', 12, 4)->nullable();
            $table->decimal('eth_change_1h_percent', 12, 4)->nullable();
            $table->decimal('eth_change_4h_percent', 12, 4)->nullable();
            $table->decimal('eth_change_24h_percent', 12, 4)->nullable();
            $table->string('market_condition', 32)->nullable()->index();
            $table->text('notes')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_snapshots');
    }
};
