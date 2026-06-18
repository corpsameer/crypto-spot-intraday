<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolio_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portfolio_account_id')->constrained('portfolio_accounts')->restrictOnDelete();
            $table->foreignId('trade_plan_id')->nullable()->constrained('trade_plans')->nullOnDelete();
            $table->foreignId('simulated_trade_id')->nullable()->constrained('simulated_trades')->nullOnDelete();
            $table->string('transaction_type')->index();
            $table->string('direction')->nullable()->index();
            $table->decimal('amount', 18, 2)->default(0.00);
            $table->decimal('balance_before', 18, 2)->nullable();
            $table->decimal('balance_after', 18, 2)->nullable();
            $table->decimal('reserved_before', 18, 2)->nullable();
            $table->decimal('reserved_after', 18, 2)->nullable();
            $table->decimal('deployed_before', 18, 2)->nullable();
            $table->decimal('deployed_after', 18, 2)->nullable();
            $table->decimal('realized_pnl_before', 18, 2)->nullable();
            $table->decimal('realized_pnl_after', 18, 2)->nullable();
            $table->text('description')->nullable();
            $table->string('reference_type')->nullable()->index();
            $table->unsignedBigInteger('reference_id')->nullable()->index();
            $table->timestamp('transaction_time')->nullable()->index();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_transactions');
    }
};
