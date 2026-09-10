<?php

namespace App\Services;

use App\Models\SimulatedTrade;
use Carbon\CarbonImmutable;

class PostSlRecoveryAnalyzer
{
    private const HORIZONS = [7, 15, 30, 60];

    /** @param array<int, array{time: CarbonImmutable,open: float,high: float,low: float,close: float}> $candles */
    public function analyze(SimulatedTrade $trade, array $candles, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $entry = CarbonImmutable::parse($trade->entry_triggered_at)->utc();
        $sl = CarbonImmutable::parse($trade->sl_hit_at)->utc();
        $entryPrice = (float) $trade->entry_price; $slPrice = (float) $trade->sl_price;
        $tp1 = (float) $trade->tp1_price; $tp2 = $trade->tp2_price === null ? null : (float) $trade->tp2_price;
        $candles = $this->normalize($candles, $entry, $now);
        $postSl = array_values(array_filter($candles, fn ($c) => $c['time']->gt($sl)));
        if (! $postSl) return [];

        $tp1Candle = $this->firstHit($postSl, $tp1); $tp2Candle = $tp2 ? $this->firstHit($postSl, $tp2) : null;
        $highest = $this->extreme($postSl, 'high', true); $lowest = $this->extreme($postSl, 'low', false);
        $slDistance = $this->percent($slPrice, $entryPrice);
        $mae = null;
        if ($tp1Candle) {
            $beforeTp1 = array_filter($candles, fn ($c) => $c['time']->gte($entry) && $c['time']->lte($tp1Candle['time']));
            $maeLow = $this->extreme(array_values($beforeTp1), 'low', false);
            $mae = $maeLow ? $this->percent($maeLow['low'], $entryPrice) : null;
        }

        $result = [
            'post_sl_tp1_hit' => (bool) $tp1Candle, 'post_sl_tp1_hit_at' => $tp1Candle['time'] ?? null,
            'post_sl_tp2_hit' => (bool) $tp2Candle, 'post_sl_tp2_hit_at' => $tp2Candle['time'] ?? null,
            'hours_from_entry_to_tp1' => $tp1Candle ? $entry->floatDiffInHours($tp1Candle['time']) : null,
            'days_from_entry_to_tp1' => $tp1Candle ? $entry->floatDiffInHours($tp1Candle['time']) / 24 : null,
            'hours_from_sl_to_tp1' => $tp1Candle ? $sl->floatDiffInHours($tp1Candle['time']) : null,
            'days_from_sl_to_tp1' => $tp1Candle ? $sl->floatDiffInHours($tp1Candle['time']) / 24 : null,
            'post_sl_highest_price' => $highest['high'], 'post_sl_highest_price_at' => $highest['time'],
            'post_sl_max_gain_percent' => $this->percent($highest['high'], $entryPrice),
            'post_sl_lowest_price' => $lowest['low'], 'post_sl_lowest_price_at' => $lowest['time'],
            'post_sl_max_drawdown_percent' => $this->percent($lowest['low'], $entryPrice),
            'mae_before_tp1_percent' => $mae, 'sl_distance_percent' => $slDistance,
            'extra_drawdown_beyond_sl_percent' => $mae === null ? null : min(0, $mae - $slDistance),
            'analyzed_through' => end($postSl)['time'],
        ];

        $strategies = ['EXISTING_STRATEGY' => $this->existingStrategy($trade, $entry, $sl, $entryPrice, $slPrice)];
        foreach (self::HORIZONS as $days) {
            $end = $entry->addDays($days); $complete = $now->gte($end);
            $result["observation_{$days}d_complete"] = $complete;
            $result["tp1_within_{$days}d"] = $complete ? (bool) ($tp1Candle && $tp1Candle['time']->lte($end)) : null;
            $result["tp2_within_{$days}d"] = $complete && $tp2 !== null ? (bool) ($tp2Candle && $tp2Candle['time']->lte($end)) : null;
            $strategies["HOLD_TO_TP1_{$days}D"] = $complete ? $this->holdStrategy($candles, $entry, $sl, $end, $entryPrice, $tp1) : null;
        }
        $gaps = $this->gapCount($candles);
        $result['data_quality_status'] = $gaps ? 'gaps_detected' : 'complete';
        $result['data_quality_notes'] = $gaps ? "{$gaps} gap(s) longer than two hours detected; hits are based only on observed candles." : null;
        $result['strategy_results'] = $strategies;
        return $result;
    }

    private function normalize(array $candles, CarbonImmutable $from, CarbonImmutable $through): array
    {
        $valid = [];
        foreach ($candles as $c) {
            if (! isset($c['time'], $c['high'], $c['low'], $c['close'])) continue;
            $time = $c['time'] instanceof CarbonImmutable ? $c['time']->utc() : CarbonImmutable::parse($c['time'])->utc();
            if ($time->lt($from) || $time->gt($through) || ! is_numeric($c['high']) || ! is_numeric($c['low']) || ! is_numeric($c['close']) || $c['high'] < $c['low']) continue;
            $valid[$time->getTimestamp()] = $c + ['open' => (float) ($c['open'] ?? $c['close'])]; $valid[$time->getTimestamp()]['time'] = $time;
        }
        ksort($valid); return array_values($valid);
    }

    private function firstHit(array $candles, float $target): ?array { foreach ($candles as $c) if ($c['high'] >= $target) return $c; return null; }
    private function extreme(array $candles, string $key, bool $maximum): ?array { if (! $candles) return null; return array_reduce($candles, fn ($best, $c) => $best === null || ($maximum ? $c[$key] > $best[$key] : $c[$key] < $best[$key]) ? $c : $best); }
    private function percent(float $price, float $entry): float { return (($price - $entry) / $entry) * 100; }
    private function gapCount(array $candles): int { $gaps = 0; for ($i = 1; $i < count($candles); $i++) if ($candles[$i - 1]['time']->diffInMinutes($candles[$i]['time']) > 120) $gaps++; return $gaps; }

    private function existingStrategy(SimulatedTrade $trade, CarbonImmutable $entry, CarbonImmutable $sl, float $entryPrice, float $slPrice): array
    {
        $exit = (float) ($trade->close_price ?: $slPrice);
        return ['eligible' => true, 'tp1_hit' => false, 'return_percent' => $this->percent($exit, $entryPrice),
            'holding_days' => $entry->floatDiffInHours($trade->closed_at ? CarbonImmutable::parse($trade->closed_at)->utc() : $sl) / 24,
            'max_drawdown_percent' => $trade->max_drawdown_percent === null ? $this->percent($slPrice, $entryPrice) : (float) $trade->max_drawdown_percent,
            'exit_price' => $exit];
    }

    private function holdStrategy(array $candles, CarbonImmutable $entry, CarbonImmutable $sl, CarbonImmutable $end, float $entryPrice, float $tp1): ?array
    {
        $observed = array_values(array_filter($candles, fn ($c) => $c['time']->gte($entry) && $c['time']->lte($end)));
        if (! $observed) return null;
        $hit = $this->firstHit(array_values(array_filter($observed, fn ($c) => $c['time']->gt($sl))), $tp1); $exitTime = $hit['time'] ?? end($observed)['time']; $exitPrice = $hit ? $tp1 : end($observed)['close'];
        $untilExit = array_values(array_filter($observed, fn ($c) => $c['time']->lte($exitTime))); $low = $this->extreme($untilExit, 'low', false);
        return ['eligible' => true, 'tp1_hit' => (bool) $hit, 'return_percent' => $this->percent($exitPrice, $entryPrice),
            'holding_days' => $entry->floatDiffInHours($exitTime) / 24, 'max_drawdown_percent' => $this->percent($low['low'], $entryPrice), 'exit_price' => $exitPrice];
    }
}
