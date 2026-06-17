<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('simulated_trades')) {
            Schema::create('simulated_trades', function (Blueprint $table): void {
                $table->id();
                $table->timestamps();
            });
        }

        Schema::table('simulated_trades', function (Blueprint $table): void {
            $this->foreignIdIfMissing($table, 'scan_run_id', 'scan_runs');
            $this->foreignIdIfMissing($table, 'scan_result_id', 'scan_results');
            $this->foreignIdIfMissing($table, 'candidate_watchlist_id', 'candidate_watchlists');
            $this->foreignIdIfMissing($table, 'trade_plan_id', 'trade_plans');
            $this->foreignIdIfMissing($table, 'spot_symbol_id', 'spot_symbols');
            $this->foreignIdIfMissing($table, 'scanner_metric_id', 'scanner_metrics');

            $this->stringIfMissing($table, 'coindcx_symbol', 32, true, false);
            $this->stringIfMissing($table, 'api_pair', 64);
            $this->stringIfMissing($table, 'base_asset', 32);
            $this->stringIfMissing($table, 'quote_asset', 32);
            $this->stringIfMissing($table, 'side', 16, false, false, 'long');
            $this->stringIfMissing($table, 'status', 32, false, false, 'pending');
            $this->stringIfMissing($table, 'source', 32, false, false, 'trade_plan');

            $this->decimalIfMissing($table, 'planned_entry_price', 30, 12);
            $this->decimalIfMissing($table, 'trigger_price', 30, 12);
            $this->decimalIfMissing($table, 'entry_price', 30, 12);
            $this->timestampIfMissing($table, 'entry_triggered_at');
            $this->decimalIfMissing($table, 'tp1_price', 30, 12);
            $this->decimalIfMissing($table, 'tp2_price', 30, 12);
            $this->decimalIfMissing($table, 'sl_price', 30, 12);
            $this->decimalIfMissing($table, 'trailing_start_price', 30, 12);
            $this->decimalIfMissing($table, 'current_trailing_sl_price', 30, 12);
            if (! Schema::hasColumn('simulated_trades', 'trailing_active')) {
                $table->boolean('trailing_active')->default(false);
            }
            $this->decimalIfMissing($table, 'tp1_percent', 12, 4);
            $this->decimalIfMissing($table, 'tp2_percent', 12, 4);
            $this->decimalIfMissing($table, 'sl_percent', 12, 4);
            $this->decimalIfMissing($table, 'latest_price', 30, 12);
            $this->decimalIfMissing($table, 'highest_price', 30, 12);
            $this->decimalIfMissing($table, 'lowest_price', 30, 12);
            $this->decimalIfMissing($table, 'max_gain_percent', 12, 4);
            $this->decimalIfMissing($table, 'max_drawdown_percent', 12, 4);
            $this->decimalIfMissing($table, 'current_pnl_percent', 12, 4);
            $this->decimalIfMissing($table, 'final_pnl_percent', 12, 4);
            $this->timestampIfMissing($table, 'tp1_hit_at');
            $this->timestampIfMissing($table, 'tp2_hit_at');
            $this->timestampIfMissing($table, 'sl_hit_at');
            $this->timestampIfMissing($table, 'trailing_started_at');
            $this->timestampIfMissing($table, 'trailing_stopped_at');
            $this->timestampIfMissing($table, 'closed_at');
            $this->timestampIfMissing($table, 'expires_at');
            $this->decimalIfMissing($table, 'close_price', 30, 12);
            $this->stringIfMissing($table, 'close_reason', 64);
            $this->decimalIfMissing($table, 'score', 12, 4);
            $this->stringIfMissing($table, 'score_label', 32);
            $this->stringIfMissing($table, 'entry_strategy', 32);
            if (! Schema::hasColumn('simulated_trades', 'notes')) {
                $table->text('notes')->nullable();
            }
            if (! Schema::hasColumn('simulated_trades', 'raw_payload')) {
                $table->json('raw_payload')->nullable();
            }
        });

        Schema::table('simulated_trades', function (Blueprint $table): void {
            $this->indexIfMissing($table, 'status');
            $this->indexIfMissing($table, 'coindcx_symbol');
            $this->indexIfMissing($table, 'side');
            $this->indexIfMissing($table, 'source');
            $this->indexIfMissing($table, 'entry_strategy');
            $this->indexIfMissing($table, 'score');
            $this->indexIfMissing($table, 'score_label');
            $this->indexIfMissing($table, 'entry_triggered_at');
            $this->indexIfMissing($table, 'closed_at');
            $this->indexIfMissing($table, 'expires_at');
            $this->indexIfMissing($table, 'trailing_active');
            $this->indexIfMissing($table, ['status', 'expires_at']);
            $this->indexIfMissing($table, ['coindcx_symbol', 'status']);
        });
    }

    public function down(): void {}

    private function foreignIdIfMissing(Blueprint $table, string $column, string $foreignTable): void
    {
        if (! Schema::hasColumn('simulated_trades', $column)) {
            $table->foreignId($column)->nullable()->index()->constrained($foreignTable)->nullOnDelete();
        }
    }

    private function stringIfMissing(Blueprint $table, string $column, int $length, bool $nullable = true, bool $index = false, ?string $default = null): void
    {
        if (! Schema::hasColumn('simulated_trades', $column)) {
            $definition = $table->string($column, $length);
            if ($nullable) $definition->nullable();
            if ($default !== null) $definition->default($default);
            if ($index) $definition->index();
        }
    }

    private function decimalIfMissing(Blueprint $table, string $column, int $precision, int $scale): void
    {
        if (! Schema::hasColumn('simulated_trades', $column)) {
            $table->decimal($column, $precision, $scale)->nullable();
        }
    }

    private function timestampIfMissing(Blueprint $table, string $column): void
    {
        if (! Schema::hasColumn('simulated_trades', $column)) {
            $table->timestamp($column)->nullable();
        }
    }

    private function indexIfMissing(Blueprint $table, string|array $columns): void
    {
        $indexName = 'simulated_trades_'.implode('_', (array) $columns).'_index';
        if (! $this->hasIndex('simulated_trades', $indexName)) {
            $table->index($columns, $indexName);
        }
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $indexName) return true;
        }
        return false;
    }
};
