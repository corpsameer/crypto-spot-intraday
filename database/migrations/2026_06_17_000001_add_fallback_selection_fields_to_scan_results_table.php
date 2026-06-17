<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scan_results', function (Blueprint $table): void {
            if (! Schema::hasColumn('scan_results', 'selection_rank')) {
                $table->unsignedInteger('selection_rank')->nullable()->after('score_passed');
            }
            if (! Schema::hasColumn('scan_results', 'selection_type')) {
                $table->string('selection_type', 32)->nullable()->after('selection_rank');
            }
            if (! Schema::hasColumn('scan_results', 'selected_for_watchlist')) {
                $table->boolean('selected_for_watchlist')->default(false)->after('selection_type');
            }
            if (! Schema::hasColumn('scan_results', 'selection_reason')) {
                $table->text('selection_reason')->nullable()->after('selected_for_watchlist');
            }

            $table->index(['scan_run_id', 'selected_for_watchlist'], 'scan_results_run_selected_idx');
            $table->index(['scan_run_id', 'selection_type'], 'scan_results_run_selection_type_idx');
            $table->index(['scan_run_id', 'selection_rank'], 'scan_results_run_selection_rank_idx');
        });
    }

    public function down(): void
    {
        Schema::table('scan_results', function (Blueprint $table): void {
            $table->dropIndex('scan_results_run_selected_idx');
            $table->dropIndex('scan_results_run_selection_type_idx');
            $table->dropIndex('scan_results_run_selection_rank_idx');

            if (Schema::hasColumn('scan_results', 'selection_reason')) {
                $table->dropColumn('selection_reason');
            }
            if (Schema::hasColumn('scan_results', 'selected_for_watchlist')) {
                $table->dropColumn('selected_for_watchlist');
            }
            if (Schema::hasColumn('scan_results', 'selection_type')) {
                $table->dropColumn('selection_type');
            }
            if (Schema::hasColumn('scan_results', 'selection_rank')) {
                $table->dropColumn('selection_rank');
            }
        });
    }
};
