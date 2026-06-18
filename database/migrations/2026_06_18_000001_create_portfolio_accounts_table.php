<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->index();
            $table->string('currency')->default('INR')->index();
            $table->decimal('starting_capital', 18, 2)->default(100000.00);
            $table->decimal('current_cash', 18, 2)->default(100000.00);
            $table->decimal('reserved_cash', 18, 2)->default(0.00);
            $table->decimal('deployed_capital', 18, 2)->default(0.00);
            $table->decimal('realized_pnl', 18, 2)->default(0.00);
            $table->decimal('unrealized_pnl', 18, 2)->default(0.00);
            $table->decimal('total_equity', 18, 2)->default(100000.00);
            $table->decimal('total_return_percent', 12, 4)->default(0.0000);
            $table->integer('max_open_trades')->default(3);
            $table->integer('preferred_open_trades')->default(2);
            $table->integer('max_pending_trade_plans')->default(3);
            $table->decimal('reserve_cash_percent', 8, 4)->default(10.0000);
            $table->decimal('min_trade_capital', 18, 2)->default(10000.00);
            $table->decimal('max_trade_capital', 18, 2)->default(40000.00);
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_accounts');
    }
};
