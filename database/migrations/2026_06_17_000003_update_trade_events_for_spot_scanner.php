<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('trade_events')) {
            Schema::create('trade_events', function (Blueprint $table): void {
                $table->id();
                $table->timestamps();
            });
        }

        Schema::table('trade_events', function (Blueprint $table): void {
            $this->foreignIdIfMissing($table, 'simulated_trade_id', 'simulated_trades');
            $this->foreignIdIfMissing($table, 'trade_plan_id', 'trade_plans');
            $this->foreignIdIfMissing($table, 'scan_run_id', 'scan_runs');
            $this->foreignIdIfMissing($table, 'scan_result_id', 'scan_results');
            $this->foreignIdIfMissing($table, 'candidate_watchlist_id', 'candidate_watchlists');
            $this->foreignIdIfMissing($table, 'spot_symbol_id', 'spot_symbols');
            $this->stringIfMissing($table, 'coindcx_symbol', 32);
            $this->stringIfMissing($table, 'event_type', 64, false, 'EVENT');
            $this->timestampIfMissing($table, 'event_time', true);
            $this->decimalIfMissing($table, 'event_price', 30, 12);
            $this->decimalIfMissing($table, 'previous_price', 30, 12);
            $this->decimalIfMissing($table, 'trigger_price', 30, 12);
            $this->decimalIfMissing($table, 'actual_price_move_percent', 12, 4);
            $this->decimalIfMissing($table, 'pnl_percent', 12, 4);
            $this->decimalIfMissing($table, 'max_gain_percent', 12, 4);
            $this->decimalIfMissing($table, 'max_drawdown_percent', 12, 4);
            $this->stringIfMissing($table, 'previous_status', 32);
            $this->stringIfMissing($table, 'new_status', 32);
            if (! Schema::hasColumn('trade_events', 'message')) $table->text('message')->nullable();
            if (! Schema::hasColumn('trade_events', 'raw_payload')) $table->json('raw_payload')->nullable();
        });

        Schema::table('trade_events', function (Blueprint $table): void {
            $this->indexIfMissing($table, 'simulated_trade_id');
            $this->indexIfMissing($table, 'trade_plan_id');
            $this->indexIfMissing($table, 'event_type');
            $this->indexIfMissing($table, 'event_time');
            $this->indexIfMissing($table, 'coindcx_symbol');
            $this->indexIfMissing($table, 'new_status');
            $this->indexIfMissing($table, ['simulated_trade_id', 'event_type']);
            $this->indexIfMissing($table, ['coindcx_symbol', 'event_time']);
        });
    }

    public function down(): void {}

    private function foreignIdIfMissing(Blueprint $table, string $column, string $foreignTable): void
    {
        if (! Schema::hasColumn('trade_events', $column)) {
            $definition = $table->foreignId($column)->nullable()->index();
            if ($column === 'simulated_trade_id') {
                $definition->constrained($foreignTable)->cascadeOnDelete();
            } else {
                $definition->constrained($foreignTable)->nullOnDelete();
            }
        }
    }

    private function stringIfMissing(Blueprint $table, string $column, int $length, bool $nullable = true, ?string $default = null): void
    {
        if (! Schema::hasColumn('trade_events', $column)) {
            $definition = $table->string($column, $length);
            if ($nullable) $definition->nullable();
            if ($default !== null) $definition->default($default);
        }
    }

    private function decimalIfMissing(Blueprint $table, string $column, int $precision, int $scale): void
    {
        if (! Schema::hasColumn('trade_events', $column)) $table->decimal($column, $precision, $scale)->nullable();
    }

    private function timestampIfMissing(Blueprint $table, string $column, bool $defaultNow = false): void
    {
        if (! Schema::hasColumn('trade_events', $column)) {
            $definition = $table->timestamp($column)->nullable();
            if ($defaultNow) $definition->useCurrent();
        }
    }

    private function indexIfMissing(Blueprint $table, string|array $columns): void
    {
        $indexName = 'trade_events_'.implode('_', (array) $columns).'_index';
        if (! $this->hasIndex('trade_events', $indexName)) $table->index($columns, $indexName);
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $indexName) return true;
        }
        return false;
    }
};
