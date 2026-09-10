<?php

namespace Tests\Feature;

use App\Services\CoinDCX\CoinDCXHistoricalCandleService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AnalyzePostSlRecoveryCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('simulated_trades', function (Blueprint $t): void { $t->id(); $t->string('status')->nullable(); $t->string('close_reason')->nullable(); $t->string('coindcx_symbol'); $t->string('api_pair')->nullable(); $t->decimal('entry_price', 20, 8)->nullable(); $t->dateTime('entry_triggered_at')->nullable(); $t->decimal('sl_price', 20, 8)->nullable(); $t->dateTime('sl_hit_at')->nullable(); $t->decimal('tp1_price', 20, 8)->nullable(); $t->decimal('tp2_price', 20, 8)->nullable(); $t->decimal('close_price', 20, 8)->nullable(); $t->dateTime('closed_at')->nullable(); $t->decimal('max_drawdown_percent', 10, 4)->nullable(); $t->timestamps(); });
        $migration = require database_path('migrations/2026_09_10_000001_create_spot_trade_recovery_analyses_table.php'); $migration->up();
    }

    public function test_command_is_rerunnable_without_duplicate_analysis_rows(): void
    {
        \DB::table('simulated_trades')->insert(['id' => 123, 'status' => 'closed_sl', 'close_reason' => 'sl', 'coindcx_symbol' => 'BTCUSDT', 'api_pair' => 'B-BTC_USDT', 'entry_price' => 100, 'entry_triggered_at' => '2026-01-01', 'sl_price' => 95, 'sl_hit_at' => '2026-01-02', 'tp1_price' => 110, 'tp2_price' => 120, 'close_price' => 95, 'closed_at' => '2026-01-02', 'created_at' => now(), 'updated_at' => now()]);
        $history = $this->mock(CoinDCXHistoricalCandleService::class);
        $history->shouldReceive('hourly')->twice()->andReturn([['time' => CarbonImmutable::parse('2026-01-03 UTC'), 'open' => 95.0, 'high' => 111.0, 'low' => 90.0, 'close' => 110.0]]);
        $this->artisan('trades:analyze-post-sl-recovery', ['--trade-id' => 123])->assertSuccessful();
        $this->artisan('trades:analyze-post-sl-recovery', ['--trade-id' => 123])->assertSuccessful();
        $this->assertDatabaseCount('spot_trade_recovery_analyses', 1);
        $this->assertDatabaseHas('spot_trade_recovery_analyses', ['simulated_trade_id' => 123, 'post_sl_tp1_hit' => true]);
    }
}
