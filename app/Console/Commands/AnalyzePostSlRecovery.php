<?php

namespace App\Console\Commands;

use App\Models\SimulatedTrade;
use App\Models\SpotTradeRecoveryAnalysis;
use App\Services\CoinDCX\CoinDCXHistoricalCandleService;
use App\Services\PostSlRecoveryAnalyzer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

class AnalyzePostSlRecovery extends Command
{
    protected $signature = 'trades:analyze-post-sl-recovery
        {--trade-id= : Analyze one simulated trade ID}
        {--from= : Analyze trades entered on or after this UTC date/time}
        {--refresh : Discard cached CoinDCX chunks before fetching}';
    protected $description = 'Analyze CoinDCX hourly candles after simulated spot stop losses without changing trades or events.';

    public function handle(CoinDCXHistoricalCandleService $history, PostSlRecoveryAnalyzer $analyzer): int
    {
        try { $from = $this->option('from') ? CarbonImmutable::parse((string) $this->option('from'), 'UTC')->utc() : null; }
        catch (Throwable) { $this->error('The --from value is not a valid date/time.'); return self::INVALID; }

        $query = SimulatedTrade::query()->where(fn ($q) => $q->where('status', 'closed_sl')->orWhere('close_reason', 'sl'))
            ->whereNotNull('sl_hit_at')->whereNotNull('entry_triggered_at')->whereNotNull('entry_price')
            ->whereNotNull('sl_price')->whereNotNull('tp1_price')->whereNotNull('api_pair');
        if ($this->option('trade-id')) $query->whereKey((int) $this->option('trade-id'));
        if ($from) $query->where('entry_triggered_at', '>=', $from);
        $trades = $query->orderBy('entry_triggered_at')->get();
        $success = collect(); $failed = [];

        foreach ($trades as $trade) {
            try {
                $now = CarbonImmutable::now('UTC');
                $candles = $history->hourly((string) $trade->api_pair, CarbonImmutable::parse($trade->entry_triggered_at)->utc(), $now, (bool) $this->option('refresh'));
                $metrics = $analyzer->analyze($trade, $candles, $now);
                if (! $metrics) throw new \RuntimeException('No valid post-SL candles');
                $row = SpotTradeRecoveryAnalysis::updateOrCreate(['simulated_trade_id' => $trade->id], array_merge([
                    'symbol' => $trade->coindcx_symbol, 'api_pair' => $trade->api_pair,
                    'entry_time' => CarbonImmutable::parse($trade->entry_triggered_at)->utc(), 'entry_price' => $trade->entry_price,
                    'sl_time' => CarbonImmutable::parse($trade->sl_hit_at)->utc(), 'sl_price' => $trade->sl_price,
                    'tp1_price' => $trade->tp1_price, 'tp2_price' => $trade->tp2_price,
                ], $metrics));
                $success->push($row);
            } catch (Throwable $e) {
                $failed[] = [$trade->id, $e->getMessage()];
                SpotTradeRecoveryAnalysis::updateOrCreate(['simulated_trade_id' => $trade->id], [
                    'symbol' => $trade->coindcx_symbol, 'api_pair' => $trade->api_pair,
                    'entry_time' => CarbonImmutable::parse($trade->entry_triggered_at)->utc(), 'entry_price' => $trade->entry_price,
                    'sl_time' => CarbonImmutable::parse($trade->sl_hit_at)->utc(), 'sl_price' => $trade->sl_price,
                    'tp1_price' => $trade->tp1_price, 'tp2_price' => $trade->tp2_price,
                    'post_sl_tp1_hit' => false, 'post_sl_tp1_hit_at' => null, 'post_sl_tp2_hit' => false, 'post_sl_tp2_hit_at' => null,
                    'hours_from_entry_to_tp1' => null, 'days_from_entry_to_tp1' => null, 'hours_from_sl_to_tp1' => null, 'days_from_sl_to_tp1' => null,
                    'post_sl_highest_price' => null, 'post_sl_highest_price_at' => null, 'post_sl_max_gain_percent' => null,
                    'post_sl_lowest_price' => null, 'post_sl_lowest_price_at' => null, 'post_sl_max_drawdown_percent' => null,
                    'mae_before_tp1_percent' => null, 'sl_distance_percent' => null, 'extra_drawdown_beyond_sl_percent' => null,
                    'tp1_within_7d' => null, 'tp1_within_15d' => null, 'tp1_within_30d' => null, 'tp1_within_60d' => null,
                    'tp2_within_7d' => null, 'tp2_within_15d' => null, 'tp2_within_30d' => null, 'tp2_within_60d' => null,
                    'observation_7d_complete' => false, 'observation_15d_complete' => false, 'observation_30d_complete' => false, 'observation_60d_complete' => false,
                    'strategy_results' => null, 'data_quality_status' => 'missing_data', 'data_quality_notes' => $e->getMessage(), 'analyzed_through' => null,
                ]);
                $this->warn("Trade {$trade->id}: {$e->getMessage()}");
            }
        }

        $strategies = $this->strategySummary($success);
        $exportRows = SpotTradeRecoveryAnalysis::query()->whereIn('simulated_trade_id', $trades->pluck('id'))->orderBy('entry_time')->get();
        $this->export($exportRows, $strategies);
        $this->report($trades->count(), $success, $failed, $strategies);
        return $failed && $success->isEmpty() ? self::FAILURE : self::SUCCESS;
    }

    private function report(int $total, Collection $rows, array $failed, array $strategies): void
    {
        $this->newLine(); $this->info('POST-SL RECOVERY ANALYSIS'); $this->line('=========================');
        $this->line("Total SL trades: {$total}"); $this->line('Successfully analyzed: '.$rows->count()); $this->line('Failed/no historical data: '.count($failed));
        foreach (['TP1', 'TP2'] as $target) {
            $this->newLine(); $this->info("{$target} RECOVERY");
            foreach ([7, 15, 30, 60] as $days) {
                $eligible = $rows->where("observation_{$days}d_complete", true); if ($target === 'TP2') $eligible = $eligible->whereNotNull('tp2_price'); $hits = $eligible->where(strtolower($target)."_within_{$days}d", true)->count();
                $this->line("Within {$days} days: {$hits} / {$eligible->count()} = ".$this->pct($hits, $eligible->count()));
            }
        }
        $eventual = $rows->where('post_sl_tp1_hit', true)->count();
        $this->line("Eventually reached TP1: {$eventual} / {$rows->count()} = ".$this->pct($eventual, $rows->count()));
        $recovered = $rows->where('post_sl_tp1_hit', true);
        $this->newLine(); $this->info('TIME TO RECOVERY');
        $this->statLines('days Entry -> TP1', $recovered->pluck('days_from_entry_to_tp1')); $this->statLines('days SL -> TP1', $recovered->pluck('days_from_sl_to_tp1'));
        $mae = $recovered->pluck('mae_before_tp1_percent')->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v)->sort()->values();
        $this->newLine(); $this->info('DRAWDOWN BEFORE TP1');
        $this->line('Median MAE before TP1: '.$this->number($this->median($mae)).'%'); $this->line('Average MAE before TP1: '.$this->number($mae->avg()).'%');
        $this->line('Worst MAE before successful TP1: '.$this->number($mae->min()).'%');
        foreach ([25, 50, 75] as $p) $this->line("{$p}th percentile: ".$this->number($this->percentile($mae, $p)).'%');
        $complete30 = $rows->where('observation_30d_complete', true);
        $this->newLine(); $this->line('Original SL trades that NEVER recovered to TP1 within a complete 30-day observation: '.$complete30->where('tp1_within_30d', false)->count());
        $this->line('Original SL trades that DID recover to TP1 within a complete 30-day observation: '.$complete30->where('tp1_within_30d', true)->count());
        $this->newLine(); $this->info('COUNTERFACTUAL STRATEGIES');
        $this->table(['Strategy', 'Eligible', 'TP1 rate', 'Avg return', 'Median return', 'Win rate'], array_map(fn ($s) => [$s['strategy'], $s['eligible_trades'], $s['tp1_hit_rate'].'%', $s['avg_return_percent'].'%', $s['median_return_percent'].'%', $s['win_rate'].'%'], $strategies));
        $this->warn('Capital simulation skipped: these historical trades overlap and the subset does not preserve enough reservation/availability state to replay the application ledger reliably. No compounding was invented.');
        $this->line('CSV: '.storage_path('app/analysis/post_sl_recovery_analysis.csv')); $this->line('CSV: '.storage_path('app/analysis/post_sl_strategy_summary.csv'));
    }

    private function strategySummary(Collection $rows): array
    {
        $names = ['EXISTING_STRATEGY', 'HOLD_TO_TP1_7D', 'HOLD_TO_TP1_15D', 'HOLD_TO_TP1_30D', 'HOLD_TO_TP1_60D']; $summary = [];
        foreach ($names as $name) {
            $items = $rows->map(fn ($r) => $r->strategy_results[$name] ?? null)->filter(fn ($v) => is_array($v) && ($v['eligible'] ?? false))->values();
            $returns = $items->pluck('return_percent')->map(fn ($v) => (float) $v); $holding = $items->pluck('holding_days')->map(fn ($v) => (float) $v); $dd = $items->pluck('max_drawdown_percent')->map(fn ($v) => (float) $v); $count = $items->count(); $hits = $items->where('tp1_hit', true)->count();
            $summary[] = ['strategy' => $name, 'eligible_trades' => $count, 'tp1_hits' => $hits, 'tp1_hit_rate' => $this->rawPct($hits, $count),
                'avg_return_percent' => round((float) $returns->avg(), 6), 'median_return_percent' => round((float) $this->median($returns), 6), 'total_simple_return_percent' => round((float) $returns->sum(), 6),
                'win_rate' => $this->rawPct($returns->filter(fn ($v) => $v > 0)->count(), $count), 'avg_holding_days' => round((float) $holding->avg(), 6), 'median_holding_days' => round((float) $this->median($holding), 6),
                'avg_max_drawdown_percent' => round((float) $dd->avg(), 6), 'worst_max_drawdown_percent' => $count ? round((float) $dd->min(), 6) : null];
        }
        return $summary;
    }

    private function export(Collection $rows, array $strategies): void
    {
        $dir = storage_path('app/analysis'); if (! is_dir($dir)) mkdir($dir, 0775, true);
        $columns = ['simulated_trade_id','symbol','api_pair','entry_time','entry_price','sl_time','sl_price','tp1_price','tp2_price','post_sl_tp1_hit','post_sl_tp1_hit_at','post_sl_tp2_hit','post_sl_tp2_hit_at','hours_from_entry_to_tp1','days_from_entry_to_tp1','hours_from_sl_to_tp1','days_from_sl_to_tp1','post_sl_highest_price','post_sl_highest_price_at','post_sl_max_gain_percent','post_sl_lowest_price','post_sl_lowest_price_at','post_sl_max_drawdown_percent','mae_before_tp1_percent','sl_distance_percent','extra_drawdown_beyond_sl_percent','tp1_within_7d','tp1_within_15d','tp1_within_30d','tp1_within_60d','tp2_within_7d','tp2_within_15d','tp2_within_30d','tp2_within_60d','observation_7d_complete','observation_15d_complete','observation_30d_complete','observation_60d_complete','analyzed_through','data_quality_status','data_quality_notes'];
        $handle = fopen($dir.'/post_sl_recovery_analysis.csv', 'w'); fputcsv($handle, $columns);
        foreach ($rows as $row) fputcsv($handle, array_map(fn ($key) => $this->csvValue($row->{$key}), $columns)); fclose($handle);
        $handle = fopen($dir.'/post_sl_strategy_summary.csv', 'w'); $headers = array_keys($strategies[0]); fputcsv($handle, $headers); foreach ($strategies as $strategy) fputcsv($handle, $strategy); fclose($handle);
    }

    private function csvValue(mixed $value): mixed { if ($value instanceof \DateTimeInterface) return CarbonImmutable::instance($value)->utc()->toIso8601String(); if (is_bool($value)) return $value ? 1 : 0; return $value; }
    private function statLines(string $label, Collection $values): void { $values = $values->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v); $this->line('Median '.$label.': '.$this->number($this->median($values))); $this->line('Average '.$label.': '.$this->number($values->avg())); }
    private function median(Collection $values): ?float { $v = $values->sort()->values(); $n = $v->count(); return ! $n ? null : ($n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2); }
    private function percentile(Collection $values, int $percent): ?float { $v = $values->sort()->values(); if ($v->isEmpty()) return null; $rank = ($percent / 100) * ($v->count() - 1); $low = (int) floor($rank); $high = (int) ceil($rank); return $v[$low] + ($v[$high] - $v[$low]) * ($rank - $low); }
    private function rawPct(int $part, int $total): float { return $total ? round($part / $total * 100, 4) : 0; }
    private function pct(int $part, int $total): string { return number_format($this->rawPct($part, $total), 2).'%'; }
    private function number(mixed $value): string { return $value === null ? 'n/a' : number_format((float) $value, 4); }
}
