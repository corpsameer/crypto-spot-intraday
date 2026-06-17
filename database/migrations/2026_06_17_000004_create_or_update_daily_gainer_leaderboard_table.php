<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('daily_gainer_leaderboard')) {
            Schema::create('daily_gainer_leaderboard', function (Blueprint $table): void {
                $table->id();
                $table->date('leaderboard_date')->index();
                $table->timestamp('run_time')->nullable()->index();
                $table->string('source')->default('coindcx_ticker');
                $table->string('quote_filter', 16)->default('USDT')->index();
                $table->integer('rank')->index();
                $table->foreignId('spot_symbol_id')->nullable()->index()->constrained('spot_symbols')->nullOnDelete();
                $table->string('coindcx_symbol', 32)->index();
                $table->string('api_pair', 64)->nullable();
                $table->string('base_asset', 32)->nullable();
                $table->string('quote_asset', 32)->nullable()->index();
                $table->decimal('last_price', 30, 12)->nullable();
                $table->decimal('open_price_24h', 30, 12)->nullable();
                $table->decimal('high_price_24h', 30, 12)->nullable();
                $table->decimal('low_price_24h', 30, 12)->nullable();
                $table->decimal('change_24h_percent', 12, 4)->nullable()->index();
                $table->decimal('abs_change_24h_percent', 12, 4)->nullable();
                $table->decimal('volume_24h', 30, 8)->nullable();
                $table->decimal('quote_volume_24h', 30, 8)->nullable()->index();
                $table->decimal('bid_price', 30, 12)->nullable();
                $table->decimal('ask_price', 30, 12)->nullable();
                $table->decimal('spread_percent', 12, 4)->nullable();
                $table->boolean('is_top_gainer')->default(false)->index();
                $table->boolean('is_top_loser')->default(false)->index();
                $table->boolean('matched_in_scan')->default(false)->index();
                $table->boolean('selected_for_watchlist')->default(false)->index();
                $table->boolean('trade_plan_created')->default(false)->index();
                $table->boolean('simulated_trade_created')->default(false)->index();
                $table->foreignId('best_scan_run_id')->nullable()->index()->constrained('scan_runs')->nullOnDelete();
                $table->foreignId('best_scan_result_id')->nullable()->index()->constrained('scan_results')->nullOnDelete();
                $table->decimal('best_final_score', 12, 4)->nullable();
                $table->string('best_score_label')->nullable();
                $table->text('notes')->nullable();
                $table->json('raw_payload')->nullable();
                $table->timestamps();
                $table->unique(['leaderboard_date', 'coindcx_symbol', 'quote_filter'], 'daily_gainers_date_symbol_quote_unique');
            });
            return;
        }

        Schema::table('daily_gainer_leaderboard', function (Blueprint $table): void {
            $this->addIfMissing($table, 'leaderboard_date', fn () => $table->date('leaderboard_date')->nullable()->index());
            $this->addIfMissing($table, 'run_time', fn () => $table->timestamp('run_time')->nullable()->index());
            $this->addIfMissing($table, 'source', fn () => $table->string('source')->default('coindcx_ticker'));
            $this->addIfMissing($table, 'quote_filter', fn () => $table->string('quote_filter', 16)->default('USDT')->index());
            $this->addIfMissing($table, 'rank', fn () => $table->integer('rank')->nullable()->index());
            $this->addIfMissing($table, 'spot_symbol_id', fn () => $table->unsignedBigInteger('spot_symbol_id')->nullable()->index());
            $this->addIfMissing($table, 'coindcx_symbol', fn () => $table->string('coindcx_symbol', 32)->nullable()->index());
            $this->addIfMissing($table, 'api_pair', fn () => $table->string('api_pair', 64)->nullable());
            $this->addIfMissing($table, 'base_asset', fn () => $table->string('base_asset', 32)->nullable());
            $this->addIfMissing($table, 'quote_asset', fn () => $table->string('quote_asset', 32)->nullable()->index());
            foreach (['last_price','open_price_24h','high_price_24h','low_price_24h','bid_price','ask_price'] as $column) $this->addIfMissing($table, $column, fn () => $table->decimal($column, 30, 12)->nullable());
            $this->addIfMissing($table, 'change_24h_percent', fn () => $table->decimal('change_24h_percent', 12, 4)->nullable()->index());
            $this->addIfMissing($table, 'abs_change_24h_percent', fn () => $table->decimal('abs_change_24h_percent', 12, 4)->nullable());
            $this->addIfMissing($table, 'volume_24h', fn () => $table->decimal('volume_24h', 30, 8)->nullable());
            $this->addIfMissing($table, 'quote_volume_24h', fn () => $table->decimal('quote_volume_24h', 30, 8)->nullable()->index());
            $this->addIfMissing($table, 'spread_percent', fn () => $table->decimal('spread_percent', 12, 4)->nullable());
            foreach (['is_top_gainer','is_top_loser','matched_in_scan','selected_for_watchlist','trade_plan_created','simulated_trade_created'] as $column) $this->addIfMissing($table, $column, fn () => $table->boolean($column)->default(false)->index());
            $this->addIfMissing($table, 'best_scan_run_id', fn () => $table->unsignedBigInteger('best_scan_run_id')->nullable()->index());
            $this->addIfMissing($table, 'best_scan_result_id', fn () => $table->unsignedBigInteger('best_scan_result_id')->nullable()->index());
            $this->addIfMissing($table, 'best_final_score', fn () => $table->decimal('best_final_score', 12, 4)->nullable());
            $this->addIfMissing($table, 'best_score_label', fn () => $table->string('best_score_label')->nullable());
            $this->addIfMissing($table, 'notes', fn () => $table->text('notes')->nullable());
            $this->addIfMissing($table, 'raw_payload', fn () => $table->json('raw_payload')->nullable());
        });
    }

    public function down(): void {}

    private function addIfMissing(Blueprint $table, string $column, callable $callback): void
    {
        if (! Schema::hasColumn('daily_gainer_leaderboard', $column)) $callback();
    }
};
