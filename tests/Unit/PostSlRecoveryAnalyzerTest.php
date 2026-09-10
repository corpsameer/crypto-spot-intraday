<?php

namespace Tests\Unit;

use App\Models\SimulatedTrade;
use App\Services\PostSlRecoveryAnalyzer;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PostSlRecoveryAnalyzerTest extends TestCase
{
    private PostSlRecoveryAnalyzer $analyzer;
    protected function setUp(): void { parent::setUp(); $this->analyzer = new PostSlRecoveryAnalyzer; }

    public static function recoveryHorizons(): array { return [[5, 7], [10, 15], [20, 30], [40, 60]]; }

    #[DataProvider('recoveryHorizons')]
    public function test_tp1_is_classified_in_the_correct_horizon(int $hitDay, int $firstTrueHorizon): void
    {
        $result = $this->analyze([$this->candle($hitDay, 112, 80, 105)]);
        $this->assertTrue($result['post_sl_tp1_hit']);
        foreach ([7, 15, 30, 60] as $days) $this->assertSame($days >= $firstTrueHorizon, $result["tp1_within_{$days}d"]);
    }

    public function test_tp1_never_reached_uses_last_close_at_horizon(): void
    {
        $result = $this->analyze([$this->candle(2, 105, 85, 90), $this->candle(7, 109, 88, 93)]);
        $this->assertFalse($result['post_sl_tp1_hit']);
        $this->assertFalse($result['tp1_within_7d']);
        $this->assertSame(93.0, $result['strategy_results']['HOLD_TO_TP1_7D']['exit_price']);
        $this->assertEqualsWithDelta(-7, $result['strategy_results']['HOLD_TO_TP1_7D']['return_percent'], .0001);
    }

    public function test_incomplete_30_day_observation_is_unknown_not_failure(): void
    {
        $result = $this->analyze([$this->candle(2, 105, 90, 100)], CarbonImmutable::parse('2026-01-21 UTC'));
        $this->assertFalse($result['observation_30d_complete']);
        $this->assertNull($result['tp1_within_30d']);
        $this->assertNull($result['strategy_results']['HOLD_TO_TP1_30D']);
    }

    public function test_mae_includes_entry_to_recovery_and_extra_drawdown_beyond_sl(): void
    {
        $result = $this->analyze([$this->candle(0, 101, 92, 95), $this->candle(2, 112, 80, 110)]);
        $this->assertEqualsWithDelta(-20, $result['mae_before_tp1_percent'], .0001);
        $this->assertEqualsWithDelta(-5, $result['sl_distance_percent'], .0001);
        $this->assertEqualsWithDelta(-15, $result['extra_drawdown_beyond_sl_percent'], .0001);
    }

    public function test_candle_at_sl_timestamp_is_not_a_post_sl_hit(): void
    {
        $result = $this->analyze([$this->candle(1, 120, 90, 110), $this->candle(2, 105, 90, 100)]);
        $this->assertFalse($result['post_sl_tp1_hit']);
    }

    public function test_gaps_are_reported_and_do_not_create_false_hits(): void
    {
        $result = $this->analyze([$this->candle(2, 109, 80, 100), $this->candle(6, 109, 90, 100)]);
        $this->assertFalse($result['post_sl_tp1_hit']);
        $this->assertSame('gaps_detected', $result['data_quality_status']);
    }

    private function analyze(array $extra, ?CarbonImmutable $now = null): array
    {
        $candles = array_merge([$this->candle(0, 101, 94, 98), $this->candle(1, 100, 94, 95)], $extra);
        return $this->analyzer->analyze($this->trade(), $candles, $now ?? CarbonImmutable::parse('2026-04-01 UTC'));
    }

    private function trade(): SimulatedTrade
    {
        return new SimulatedTrade(['entry_triggered_at' => '2026-01-01 00:00:00', 'entry_price' => 100, 'sl_hit_at' => '2026-01-02 00:00:00', 'sl_price' => 95, 'tp1_price' => 110, 'tp2_price' => 120, 'close_price' => 95, 'closed_at' => '2026-01-02 00:00:00']);
    }

    private function candle(int $day, float $high, float $low, float $close): array
    {
        return ['time' => CarbonImmutable::parse('2026-01-01 UTC')->addDays($day), 'open' => 100.0, 'high' => $high, 'low' => $low, 'close' => $close];
    }
}
