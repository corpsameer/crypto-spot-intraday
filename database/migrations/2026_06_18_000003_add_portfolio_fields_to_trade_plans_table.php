<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_plans', function (Blueprint $table): void {
            if (! Schema::hasColumn('trade_plans', 'portfolio_account_id')) { $table->unsignedBigInteger('portfolio_account_id')->nullable()->index(); }
            if (! Schema::hasColumn('trade_plans', 'allocated_capital')) { $table->decimal('allocated_capital', 18, 2)->nullable(); }
            if (! Schema::hasColumn('trade_plans', 'allocation_percent')) { $table->decimal('allocation_percent', 8, 4)->nullable(); }
            if (! Schema::hasColumn('trade_plans', 'capital_reserved_at')) { $table->timestamp('capital_reserved_at')->nullable(); }
            if (! Schema::hasColumn('trade_plans', 'capital_released_at')) { $table->timestamp('capital_released_at')->nullable(); }
            if (! Schema::hasColumn('trade_plans', 'portfolio_status')) { $table->string('portfolio_status')->nullable()->index(); }
            if (! Schema::hasColumn('trade_plans', 'portfolio_rejection_reason')) { $table->string('portfolio_rejection_reason')->nullable()->index(); }
            if (! Schema::hasColumn('trade_plans', 'portfolio_notes')) { $table->text('portfolio_notes')->nullable(); }
        });
    }

    public function down(): void
    {
        Schema::table('trade_plans', function (Blueprint $table): void {
            foreach (['portfolio_account_id','allocated_capital','allocation_percent','capital_reserved_at','capital_released_at','portfolio_status','portfolio_rejection_reason','portfolio_notes'] as $column) {
                if (Schema::hasColumn('trade_plans', $column)) { $table->dropColumn($column); }
            }
        });
    }
};
