<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('simulated_trades', function (Blueprint $table): void {
            if (! Schema::hasColumn('simulated_trades', 'portfolio_account_id')) { $table->unsignedBigInteger('portfolio_account_id')->nullable()->index(); }
            if (! Schema::hasColumn('simulated_trades', 'allocated_capital')) { $table->decimal('allocated_capital', 18, 2)->nullable(); }
            if (! Schema::hasColumn('simulated_trades', 'allocation_percent')) { $table->decimal('allocation_percent', 8, 4)->nullable(); }
            if (! Schema::hasColumn('simulated_trades', 'entry_value')) { $table->decimal('entry_value', 18, 2)->nullable(); }
            if (! Schema::hasColumn('simulated_trades', 'current_value')) { $table->decimal('current_value', 18, 2)->nullable(); }
            if (! Schema::hasColumn('simulated_trades', 'close_value')) { $table->decimal('close_value', 18, 2)->nullable(); }
            if (! Schema::hasColumn('simulated_trades', 'unrealized_pnl_amount')) { $table->decimal('unrealized_pnl_amount', 18, 2)->nullable(); }
            if (! Schema::hasColumn('simulated_trades', 'realized_pnl_amount')) { $table->decimal('realized_pnl_amount', 18, 2)->nullable(); }
            if (! Schema::hasColumn('simulated_trades', 'fees_amount')) { $table->decimal('fees_amount', 18, 2)->default(0.00); }
            if (! Schema::hasColumn('simulated_trades', 'net_pnl_amount')) { $table->decimal('net_pnl_amount', 18, 2)->nullable(); }
            if (! Schema::hasColumn('simulated_trades', 'capital_released_at')) { $table->timestamp('capital_released_at')->nullable(); }
        });
    }

    public function down(): void
    {
        Schema::table('simulated_trades', function (Blueprint $table): void {
            foreach (['portfolio_account_id','allocated_capital','allocation_percent','entry_value','current_value','close_value','unrealized_pnl_amount','realized_pnl_amount','fees_amount','net_pnl_amount','capital_released_at'] as $column) {
                if (Schema::hasColumn('simulated_trades', $column)) { $table->dropColumn($column); }
            }
        });
    }
};
