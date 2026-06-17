<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('missed_gainers')) {
            Schema::create('missed_gainers', function (Blueprint $table): void {
                $table->id();
                $table->timestamps();
            });
        }

        Schema::table('missed_gainers', function (Blueprint $table): void {
            $this->dateIfMissing($table, 'analysis_date', true);
            $this->unsignedBigIntegerIfMissing($table, 'leaderboard_id', true);
            $this->integerIfMissing($table, 'leaderboard_rank', true);
            $this->unsignedBigIntegerIfMissing($table, 'spot_symbol_id', true);
            $this->stringIfMissing($table, 'coindcx_symbol', 64, false);
            $this->stringIfMissing($table, 'api_pair', 64);
            $this->stringIfMissing($table, 'base_asset', 32);
            $this->stringIfMissing($table, 'quote_asset', 32, true, true);
            $this->decimalIfMissing($table, 'actual_change_24h_percent', 12, 4, true);
            $this->decimalIfMissing($table, 'actual_last_price', 30, 12);
            $this->decimalIfMissing($table, 'actual_quote_volume_24h', 30, 8);
            $this->decimalIfMissing($table, 'actual_spread_percent', 12, 4);

            foreach (['matched_in_scan','selected_for_watchlist','trade_plan_created','simulated_trade_created','entry_triggered','tp1_hit','tp2_hit','sl_hit','trailing_hit','expired'] as $column) {
                $this->booleanIfMissing($table, $column, true);
            }

            foreach (['best_scan_run_id','best_scan_result_id','best_candidate_watchlist_id','best_trade_plan_id','best_simulated_trade_id'] as $column) {
                $this->unsignedBigIntegerIfMissing($table, $column, true);
            }
            $this->decimalIfMissing($table, 'best_final_score', 12, 4);
            $this->stringIfMissing($table, 'best_score_label', 64);
            $this->integerIfMissing($table, 'best_rank', true);
            $this->integerIfMissing($table, 'selected_rank', true);

            foreach (['miss_type','miss_reason','miss_severity','action_needed'] as $column) {
                $this->stringIfMissing($table, $column, 64, true, true);
            }
            if (! Schema::hasColumn('missed_gainers', 'notes')) $table->text('notes')->nullable();

            $this->booleanNullableIfMissing($table, 'prefilter_passed');
            $this->booleanNullableIfMissing($table, 'score_passed');
            $this->booleanNullableIfMissing($table, 'fallback_selected');
            if (! Schema::hasColumn('missed_gainers', 'rejection_reason')) $table->text('rejection_reason')->nullable();
            $this->stringIfMissing($table, 'setup_type', 64);
            $this->stringIfMissing($table, 'entry_strategy', 64);
            foreach (['planned_entry_price','trigger_price','tp1_price','tp2_price','sl_price'] as $column) {
                $this->decimalIfMissing($table, $column, 30, 12);
            }
            $this->stringIfMissing($table, 'latest_trade_status', 64);
            foreach (['current_pnl_percent','max_gain_percent','final_pnl_percent'] as $column) {
                $this->decimalIfMissing($table, $column, 12, 4);
            }
            if (! Schema::hasColumn('missed_gainers', 'raw_payload')) $table->json('raw_payload')->nullable();
            $this->timestampIfMissing($table, 'analyzed_at', true);
        });

        Schema::table('missed_gainers', function (Blueprint $table): void {
            foreach (['analysis_date','leaderboard_id','leaderboard_rank','spot_symbol_id','coindcx_symbol','quote_asset','actual_change_24h_percent','matched_in_scan','selected_for_watchlist','trade_plan_created','simulated_trade_created','entry_triggered','tp1_hit','tp2_hit','sl_hit','trailing_hit','expired','best_scan_run_id','best_scan_result_id','best_candidate_watchlist_id','best_trade_plan_id','best_simulated_trade_id','best_rank','selected_rank','miss_type','miss_reason','miss_severity','analyzed_at'] as $column) {
                $this->indexIfMissing($table, $column);
            }
            $this->uniqueIfMissing($table, ['analysis_date', 'coindcx_symbol'], 'missed_gainers_analysis_symbol_unique');
        });
    }

    public function down(): void {}

    private function dateIfMissing(Blueprint $table, string $column, bool $index = false): void { if (! Schema::hasColumn('missed_gainers', $column)) { $definition = $table->date($column)->nullable(); if ($index) $definition->index(); } }
    private function timestampIfMissing(Blueprint $table, string $column, bool $index = false): void { if (! Schema::hasColumn('missed_gainers', $column)) { $definition = $table->timestamp($column)->nullable(); if ($index) $definition->index(); } }
    private function unsignedBigIntegerIfMissing(Blueprint $table, string $column, bool $index = false): void { if (! Schema::hasColumn('missed_gainers', $column)) { $definition = $table->unsignedBigInteger($column)->nullable(); if ($index) $definition->index(); } }
    private function integerIfMissing(Blueprint $table, string $column, bool $index = false): void { if (! Schema::hasColumn('missed_gainers', $column)) { $definition = $table->integer($column)->nullable(); if ($index) $definition->index(); } }
    private function stringIfMissing(Blueprint $table, string $column, int $length, bool $nullable = true, bool $index = false): void { if (! Schema::hasColumn('missed_gainers', $column)) { $definition = $table->string($column, $length); if ($nullable) $definition->nullable(); if ($index) $definition->index(); } }
    private function decimalIfMissing(Blueprint $table, string $column, int $precision, int $scale, bool $index = false): void { if (! Schema::hasColumn('missed_gainers', $column)) { $definition = $table->decimal($column, $precision, $scale)->nullable(); if ($index) $definition->index(); } }
    private function booleanIfMissing(Blueprint $table, string $column, bool $index = false): void { if (! Schema::hasColumn('missed_gainers', $column)) { $definition = $table->boolean($column)->default(false); if ($index) $definition->index(); } }
    private function booleanNullableIfMissing(Blueprint $table, string $column): void { if (! Schema::hasColumn('missed_gainers', $column)) $table->boolean($column)->nullable(); }
    private function indexIfMissing(Blueprint $table, string $column): void { $name = 'missed_gainers_'.$column.'_index'; if (! $this->hasIndex($name) && Schema::hasColumn('missed_gainers', $column)) $table->index($column, $name); }
    private function uniqueIfMissing(Blueprint $table, array $columns, string $name): void { if (! $this->hasIndex($name)) $table->unique($columns, $name); }
    private function hasIndex(string $name): bool { foreach (Schema::getIndexes('missed_gainers') as $index) { if (($index['name'] ?? null) === $name) return true; } return false; }
};
