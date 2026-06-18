<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ScoreBucketAnalyticsController extends Controller
{
    private array $openStatuses = ['active', 'tp1_hit', 'tp2_hit', 'trailing_active', 'pending'];
    private array $closedStatuses = ['closed_sl', 'closed_trailing', 'closed_tp1', 'closed_tp2', 'expired', 'cancelled', 'error'];
    private array $bucketOrder = ['No score', '0-29', '30-39', '40-49', '50-59', '60-69', '70-79', '80+'];

    public function index(Request $request): View
    {
        $latestDates = collect([
            Schema::hasTable('scan_runs') ? DB::table('scan_runs')->max('started_at') : null,
            Schema::hasTable('missed_gainers') ? DB::table('missed_gainers')->max('analysis_date') : null,
            Schema::hasTable('simulated_trades') ? DB::table('simulated_trades')->selectRaw('MAX(COALESCE(entry_triggered_at, created_at)) as latest_date')->value('latest_date') : null,
        ])->filter();
        $latest = $latestDates->isNotEmpty() ? Carbon::parse($latestDates->max()) : now();
        $dateTo = (string) $request->query('to', $latest->toDateString());
        $dateFrom = (string) $request->query('from', $latest->copy()->subDays(6)->toDateString());
        $from = Carbon::parse($dateFrom)->startOfDay();
        $to = Carbon::parse($dateTo)->endOfDay();
        $quote = strtoupper((string) $request->query('quote', 'USDT')) ?: 'USDT';
        $minChange = (float) $request->query('min_change', 10);
        $filters = [
            'from' => $dateFrom, 'to' => $dateTo, 'quote' => $quote, 'min_change' => $minChange,
            'score_label' => (string) $request->query('score_label', 'all'),
            'selected' => (string) $request->query('selected', 'all'),
            'has_trade_plan' => (string) $request->query('has_trade_plan', 'all'),
            'has_simulated_trade' => (string) $request->query('has_simulated_trade', 'all'),
        ];

        $runIds = Schema::hasTable('scan_runs') ? DB::table('scan_runs')->whereBetween('started_at', [$from, $to])->when($quote !== 'ALL', fn ($q) => $q->where(fn ($qq) => $qq->where('quote_filter', $quote)->orWhereNull('quote_filter')))->pluck('id') : collect();
        $scanRows = $this->scanRows($runIds, $quote, $filters);
        $gainerRows = $this->gainerRows($dateFrom, $dateTo, $quote, $minChange, $filters);
        $tradeRows = $this->tradeRows($from, $to, $quote, $filters, $runIds);
        $eventsByTrade = $tradeRows->isEmpty() || ! Schema::hasTable('trade_events') ? collect() : DB::table('trade_events')->whereIn('simulated_trade_id', $tradeRows->pluck('id'))->select('simulated_trade_id', 'event_type')->get()->groupBy('simulated_trade_id')->map(fn ($rows) => $rows->pluck('event_type')->countBy());

        $scanScoreBuckets = $this->scanBucketStats($scanRows);
        $dailyGainerScoreBuckets = $this->gainerBucketStats($gainerRows);
        $tradeScoreBuckets = $this->tradeBucketStats($tradeRows, $eventsByTrade);
        $scoreLabelStats = $this->scoreLabelStats($scanRows, $gainerRows, $tradeRows);
        $summaryStats = $this->summaryStats($scanRows, $gainerRows, $tradeRows, $tradeScoreBuckets, $dailyGainerScoreBuckets, $minChange);
        $lowScoreHighGainers = $gainerRows->filter(fn ($r) => $r->best_final_score === null || (float) $r->best_final_score < 50 || ! (bool) $r->selected_for_watchlist)->sortBy([['actual_change_24h_percent', 'desc'], ['leaderboard_rank', 'asc']])->take(25)->values();
        $highScoreNonPerformers = $tradeRows->filter(fn ($t) => (((float) ($t->score ?? 0) >= 50) || in_array($t->score_label, ['strong', 'watchlist'], true)) && (($this->isClosed($t) && (float) ($t->final_pnl_percent ?? 0) <= 0) || (! $this->isClosed($t) && (float) ($t->current_pnl_percent ?? 0) < 0) || (float) ($t->max_drawdown_percent ?? 0) <= -3))->sortByDesc('updated_at')->take(25)->values();
        $latestBucketRows = $scanRows->sortByDesc('evaluated_at')->take(25)->values();

        return view('analytics.score-buckets', compact('filters', 'summaryStats', 'scanScoreBuckets', 'dailyGainerScoreBuckets', 'tradeScoreBuckets', 'scoreLabelStats', 'lowScoreHighGainers', 'highScoreNonPerformers', 'latestBucketRows'));
    }

    private function scanRows(Collection $runIds, string $quote, array $filters): Collection
    {
        if (! Schema::hasTable('scan_results') || $runIds->isEmpty()) return collect();
        return DB::table('scan_results')->select('id','scan_run_id','candidate_watchlist_id','trade_plan_id','coindcx_symbol','quote_asset','selected_for_watchlist','trade_plan_created','change_15m_percent','change_1h_percent','volume_spike_15m','volume_spike_1h','spread_percent','orderbook_depth_usdt','final_score','score_label','evaluated_at','created_at')
            ->whereIn('scan_run_id', $runIds)->when($quote !== 'ALL', fn ($q) => $q->where('quote_asset', $quote))->when($filters['score_label'] !== 'all', fn ($q) => $q->where('score_label', $filters['score_label']))->when($filters['selected'] === 'yes', fn ($q) => $q->where('selected_for_watchlist', true))->when($filters['selected'] === 'no', fn ($q) => $q->where('selected_for_watchlist', false))->when($filters['has_trade_plan'] === 'yes', fn ($q) => $q->where(fn ($qq) => $qq->where('trade_plan_created', true)->orWhereNotNull('trade_plan_id')))->when($filters['has_trade_plan'] === 'no', fn ($q) => $q->where('trade_plan_created', false)->whereNull('trade_plan_id'))->get();
    }
    private function gainerRows(string $from, string $to, string $quote, float $min, array $filters): Collection
    { if (! Schema::hasTable('missed_gainers')) return collect(); return DB::table('missed_gainers')->select('analysis_date','leaderboard_rank','coindcx_symbol','quote_asset','actual_change_24h_percent','matched_in_scan','selected_for_watchlist','trade_plan_created','simulated_trade_created','best_final_score','best_score_label','miss_type','miss_reason','action_needed')->whereBetween('analysis_date',[$from,$to])->where('actual_change_24h_percent','>=',$min)->when($quote!=='ALL',fn($q)=>$q->where('quote_asset',$quote))->when($filters['score_label']!=='all',fn($q)=>$q->where('best_score_label',$filters['score_label']))->when($filters['selected']==='yes',fn($q)=>$q->where('selected_for_watchlist',true))->when($filters['selected']==='no',fn($q)=>$q->where('selected_for_watchlist',false))->when($filters['has_trade_plan']==='yes',fn($q)=>$q->where('trade_plan_created',true))->when($filters['has_trade_plan']==='no',fn($q)=>$q->where('trade_plan_created',false))->when($filters['has_simulated_trade']==='yes',fn($q)=>$q->where('simulated_trade_created',true))->when($filters['has_simulated_trade']==='no',fn($q)=>$q->where('simulated_trade_created',false))->get(); }
    private function tradeRows(Carbon $from, Carbon $to, string $quote, array $filters, Collection $runIds): Collection
    { if (! Schema::hasTable('simulated_trades')) return collect(); return DB::table('simulated_trades')->select('id','scan_run_id','coindcx_symbol','quote_asset','entry_strategy','status','score','score_label','current_pnl_percent','final_pnl_percent','max_gain_percent','max_drawdown_percent','entry_triggered_at','closed_at','created_at','updated_at')->where(fn($q)=>$q->whereBetween(DB::raw('COALESCE(entry_triggered_at, created_at)'),[$from,$to])->orWhereIn('scan_run_id',$runIds))->when($quote!=='ALL',fn($q)=>$q->where('quote_asset',$quote))->when($filters['score_label']!=='all',fn($q)=>$q->where('score_label',$filters['score_label']))->get(); }
    private function bucket($score): string { if ($score === null) return 'No score'; $s=(float)$score; return $s<30?'0-29':($s<40?'30-39':($s<50?'40-49':($s<60?'50-59':($s<70?'60-69':($s<80?'70-79':'80+'))))); }
    private function byBucket(Collection $rows, string $field): Collection { return collect($this->bucketOrder)->map(fn($b)=>[$b, $rows->filter(fn($r)=>$this->bucket($r->{$field})===$b)])->mapWithKeys(fn($v)=>[$v[0]=>$v[1]]); }
    private function scanBucketStats(Collection $rows): Collection { return $this->byBucket($rows,'final_score')->map(fn($r,$b)=>['bucket'=>$b,'rows'=>$r->count(),'symbols'=>$r->pluck('coindcx_symbol')->unique()->count(),'avg_score'=>$r->avg('final_score'),'selected'=>$r->where('selected_for_watchlist',true)->count(),'selected_pct'=>$this->pct($r->where('selected_for_watchlist',true)->count(),$r->count()),'watchlist'=>$r->whereNotNull('candidate_watchlist_id')->count(),'trade_plans'=>$r->filter(fn($x)=>(bool)$x->trade_plan_created||$x->trade_plan_id)->count(),'avg_15m'=>$r->avg('change_15m_percent'),'avg_1h'=>$r->avg('change_1h_percent'),'avg_volume_spike'=>$r->avg('volume_spike_15m'),'avg_spread'=>$r->avg('spread_percent'),'avg_liquidity'=>$r->avg('orderbook_depth_usdt')])->values(); }
    private function gainerBucketStats(Collection $rows): Collection { return $this->byBucket($rows,'best_final_score')->map(fn($r,$b)=>['bucket'=>$b,'count'=>$r->count(),'avg_change'=>$r->avg('actual_change_24h_percent'),'max_change'=>$r->max('actual_change_24h_percent'),'matched'=>$r->where('matched_in_scan',true)->count(),'selected'=>$r->where('selected_for_watchlist',true)->count(),'selected_pct'=>$this->pct($r->where('selected_for_watchlist',true)->count(),$r->count()),'plans'=>$r->where('trade_plan_created',true)->count(),'sim_trades'=>$r->where('simulated_trade_created',true)->count(),'captured_not_selected'=>$r->where('miss_type','captured_not_selected')->count(),'missed_completely'=>$r->where('miss_type','missed_completely')->count(),'avg_score'=>$r->avg('best_final_score')])->values(); }
    private function tradeBucketStats(Collection $rows, Collection $events): Collection { return $this->byBucket($rows,'score')->map(function($r,$b)use($events){$closed=$r->filter(fn($t)=>$this->isClosed($t));$wins=$closed->filter(fn($t)=>(float)$t->final_pnl_percent>0)->count();return ['bucket'=>$b,'trades'=>$r->count(),'open'=>$r->filter(fn($t)=>!$this->isClosed($t))->count(),'closed'=>$closed->count(),'win_rate'=>$this->pct($wins,$closed->count()),'tp1'=>$this->eventCount($r,$events,'TP1'),'tp2'=>$this->eventCount($r,$events,'TP2'),'sl'=>$this->eventCount($r,$events,'SL'),'trailing_closed'=>$r->where('status','closed_trailing')->count(),'expired'=>$r->where('status','expired')->count(),'avg_current'=>$r->avg('current_pnl_percent'),'avg_final'=>$closed->avg('final_pnl_percent'),'avg_max_gain'=>$r->avg('max_gain_percent'),'avg_max_drawdown'=>$r->avg('max_drawdown_percent'),'best_final'=>$closed->max('final_pnl_percent') ?? $r->max('max_gain_percent'),'worst_final'=>$closed->min('final_pnl_percent') ?? $r->min('max_drawdown_percent')];})->values(); }
    private function scoreLabelStats(Collection $scans, Collection $gainers, Collection $trades): Collection { return collect(['strong','watchlist','weak','fallback','No label'])->map(function($l)use($scans,$gainers,$trades){$scan=$scans->filter(fn($r)=>($r->score_label?:'No label')===$l);$gain=$gainers->filter(fn($r)=>($r->best_score_label?:'No label')===$l);$trade=$trades->filter(fn($r)=>($r->score_label?:'No label')===$l);$closed=$trade->filter(fn($t)=>$this->isClosed($t));$wins=$closed->filter(fn($t)=>(float)$t->final_pnl_percent>0)->count();return ['label'=>$l,'scan_results'=>$scan->count(),'selected'=>$scan->where('selected_for_watchlist',true)->count(),'actual_gainers'=>$gain->count(),'trades'=>$trade->count(),'win_rate'=>$this->pct($wins,$closed->count()),'avg_final'=>$closed->avg('final_pnl_percent'),'avg_change'=>$gain->avg('actual_change_24h_percent'),'captured_not_selected'=>$gain->where('miss_type','captured_not_selected')->count()]; }); }
    private function summaryStats(Collection $scans, Collection $gainers, Collection $trades, Collection $tradeBuckets, Collection $gainerBuckets, float $min): array { $best=$tradeBuckets->where('closed','>',0)->sortByDesc('avg_final')->first(); $most=$gainerBuckets->sortByDesc('captured_not_selected')->first(); return ['scored'=>$scans->whereNotNull('final_score')->count(),'no_score'=>$scans->whereNull('final_score')->count(),'selected'=>$scans->where('selected_for_watchlist',true)->count(),'trade_plans'=>$scans->filter(fn($x)=>(bool)$x->trade_plan_created||$x->trade_plan_id)->count(),'sim_trades'=>$trades->count(),'actual_gainers'=>$gainers->count(),'gainers_with_score'=>$gainers->whereNotNull('best_final_score')->count(),'gainers_without_score'=>$gainers->whereNull('best_final_score')->count(),'best_bucket'=>$best['bucket']??null,'best_bucket_avg'=>$best['avg_final']??null,'most_captured_not_selected_bucket'=>$most['bucket']??null,'most_captured_not_selected'=>$most['captured_not_selected']??0,'avg_selected_score'=>$scans->where('selected_for_watchlist',true)->avg('final_score'),'avg_gainer_score'=>$gainers->avg('best_final_score'),'min_change'=>$min]; }
    private function eventCount(Collection $rows, Collection $events, string $contains): int { return $rows->sum(fn($t)=>collect($events->get($t->id, []))->filter(fn($n,$k)=>str_contains((string)$k,$contains))->sum()); }
    private function isClosed($t): bool { return in_array($t->status, $this->closedStatuses, true) || $t->closed_at !== null; }
    private function pct(int|float $n, int|float $d): ?float { return $d > 0 ? ($n / $d) * 100 : null; }
}
