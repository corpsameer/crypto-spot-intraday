<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SetupTypeAnalyticsController extends Controller
{
    private array $openStatuses = ['active', 'tp1_hit', 'tp2_hit', 'trailing_active'];
    private array $closedStatuses = ['closed_sl', 'closed_trailing', 'closed_tp1', 'closed_tp2', 'expired', 'cancelled', 'error'];

    public function index(Request $request): View
    {
        $latestDates = collect([
            Schema::hasTable('scan_results') ? DB::table('scan_results')->max('evaluated_at') ?: DB::table('scan_results')->max('created_at') : null,
            Schema::hasTable('missed_gainers') ? DB::table('missed_gainers')->max('analysis_date') : null,
            Schema::hasTable('simulated_trades') ? DB::table('simulated_trades')->selectRaw('MAX(COALESCE(entry_triggered_at, created_at)) as latest_date')->value('latest_date') : null,
        ])->filter();
        $latest = $latestDates->isNotEmpty() ? Carbon::parse($latestDates->max()) : now();
        $dateTo = (string) $request->query('to', $latest->toDateString());
        $dateFrom = (string) $request->query('from', $latest->copy()->subDays(6)->toDateString());
        $from = Carbon::parse($dateFrom)->startOfDay();
        $to = Carbon::parse($dateTo)->endOfDay();
        $filters = [
            'from' => $dateFrom,
            'to' => $dateTo,
            'quote' => strtoupper((string) $request->query('quote', 'USDT')) ?: 'USDT',
            'setup_type' => trim((string) $request->query('setup_type', 'all')) ?: 'all',
            'entry_strategy' => (string) $request->query('entry_strategy', 'all'),
            'score_label' => (string) $request->query('score_label', 'all'),
            'selected' => (string) $request->query('selected', 'all'),
            'min_change' => (float) $request->query('min_change', 10),
            'trade_state' => (string) $request->query('trade_state', 'all'),
        ];

        $scanRows = $this->scanRows($from, $to, $filters);
        $watchlistRows = $this->watchlistRows($from, $to, $filters);
        $planRows = $this->planRows($from, $to, $filters);
        $tradeRows = $this->tradeRows($from, $to, $filters);
        $missedRows = $this->missedRows($dateFrom, $dateTo, $filters);
        $eventsByTrade = $tradeRows->isEmpty() || ! Schema::hasTable('trade_events') ? collect() : DB::table('trade_events')->whereIn('simulated_trade_id', $tradeRows->pluck('id'))->select('simulated_trade_id', 'event_type')->get()->groupBy('simulated_trade_id')->map(fn ($rows) => $rows->pluck('event_type')->countBy());

        $setupScanFunnel = $this->setupScanFunnel($scanRows, $watchlistRows, $planRows, $tradeRows, $missedRows);
        $setupTradeOutcomes = $this->setupTradeOutcomes($tradeRows, $eventsByTrade);
        $setupMissedGainers = $this->setupMissedGainers($missedRows);
        $entryStrategyStats = $this->entryStrategyStats($planRows, $tradeRows, $eventsByTrade);
        $topSymbolsBySetup = $this->topSymbolsBySetup($tradeRows);
        $reviewCandidates = $missedRows->filter(fn ($r) => $r->actual_change_24h_percent >= $filters['min_change'] && ($r->miss_type !== 'captured_trade_created' || ! (bool) $r->selected_for_watchlist || ! (bool) $r->trade_plan_created))->sort(function ($a, $b) {
            return [$this->severityRank($a->miss_severity), -1 * (float) $a->actual_change_24h_percent, (int) ($a->leaderboard_rank ?? 999999)] <=> [$this->severityRank($b->miss_severity), -1 * (float) $b->actual_change_24h_percent, (int) ($b->leaderboard_rank ?? 999999)];
        })->take(25)->values();
        $latestSetupTrades = $tradeRows->sortByDesc(fn ($t) => $t->entry_triggered_at ?: $t->created_at)->take(20)->values();
        $summaryStats = $this->summaryStats($scanRows, $planRows, $tradeRows, $missedRows, $setupTradeOutcomes, $setupMissedGainers, $filters['min_change']);

        return view('analytics.setup-types', compact('filters', 'summaryStats', 'setupScanFunnel', 'setupTradeOutcomes', 'setupMissedGainers', 'entryStrategyStats', 'topSymbolsBySetup', 'reviewCandidates', 'latestSetupTrades'));
    }

    private function rows(string $table, array $columns, Carbon|string $from, Carbon|string $to, string $dateColumn, array $filters): Collection
    {
        if (! Schema::hasTable($table)) return collect();
        $cols = collect($columns)->filter(fn ($c) => Schema::hasColumn($table, $c))->values()->all();
        if (! in_array('id', $cols, true) && Schema::hasColumn($table, 'id')) $cols[] = 'id';
        $q = DB::table($table)->select($cols)->whereBetween($dateColumn, [$from, $to]);
        if (($filters['quote'] ?? 'ALL') !== 'ALL' && Schema::hasColumn($table, 'quote_asset')) $q->where('quote_asset', $filters['quote']);
        if (($filters['score_label'] ?? 'all') !== 'all') {
            $col = Schema::hasColumn($table, 'score_label') ? 'score_label' : (Schema::hasColumn($table, 'best_score_label') ? 'best_score_label' : null);
            if ($col) $q->where($col, $filters['score_label']);
        }
        if (($filters['entry_strategy'] ?? 'all') !== 'all' && Schema::hasColumn($table, 'entry_strategy')) $q->where('entry_strategy', $filters['entry_strategy']);
        if (($filters['selected'] ?? 'all') === 'yes' && Schema::hasColumn($table, 'selected_for_watchlist')) $q->where('selected_for_watchlist', true);
        if (($filters['selected'] ?? 'all') === 'no' && Schema::hasColumn($table, 'selected_for_watchlist')) $q->where('selected_for_watchlist', false);
        return $q->get()->map(fn ($r) => $this->decorateSetup($r, $table))->filter(fn ($r) => ($filters['setup_type'] ?? 'all') === 'all' || $r->setup_type === $filters['setup_type'])->values();
    }

    private function scanRows(Carbon $from, Carbon $to, array $filters): Collection { return $this->rows('scan_results', ['id','scan_run_id','candidate_watchlist_id','trade_plan_id','coindcx_symbol','quote_asset','selected_for_watchlist','trade_plan_created','final_score','score_label','setup_type','suggested_entry_strategy','evaluated_at','created_at'], $from, $to, Schema::hasColumn('scan_results','evaluated_at') ? 'evaluated_at' : 'created_at', $filters); }
    private function watchlistRows(Carbon $from, Carbon $to, array $filters): Collection { return $this->rows('candidate_watchlists', ['id','coindcx_symbol','entry_strategy','setup_type','detected_at','created_at'], $from, $to, Schema::hasColumn('candidate_watchlists','detected_at') ? 'detected_at' : 'created_at', $filters); }
    private function planRows(Carbon $from, Carbon $to, array $filters): Collection { return $this->rows('trade_plans', ['id','scan_result_id','simulated_trade_id','coindcx_symbol','quote_asset','entry_strategy','setup_type','status','score','score_label','triggered_at','converted_at','created_at'], $from, $to, 'created_at', $filters); }
    private function tradeRows(Carbon $from, Carbon $to, array $filters): Collection { $rows = $this->rows('simulated_trades', ['id','scan_result_id','trade_plan_id','coindcx_symbol','quote_asset','entry_strategy','setup_type','status','score','score_label','current_pnl_percent','final_pnl_percent','max_gain_percent','max_drawdown_percent','tp1_hit_at','tp2_hit_at','sl_hit_at','entry_triggered_at','closed_at','created_at'], $from, $to, 'created_at', $filters); return $rows->filter(fn($t)=>$filters['trade_state']==='all' || ($filters['trade_state']==='open' && ! $this->isClosed($t)) || ($filters['trade_state']==='closed' && $this->isClosed($t)))->values(); }
    private function missedRows(string $from, string $to, array $filters): Collection { return $this->rows('missed_gainers', ['id','analysis_date','leaderboard_rank','coindcx_symbol','quote_asset','actual_change_24h_percent','matched_in_scan','selected_for_watchlist','trade_plan_created','simulated_trade_created','best_final_score','best_score_label','miss_type','miss_reason','miss_severity','action_needed','setup_type','entry_strategy'], $from, $to, 'analysis_date', $filters)->where('actual_change_24h_percent', '>=', $filters['min_change'])->values(); }

    private function decorateSetup(object $r, string $table): object { $r->setup_type = $r->setup_type ?? ($r->entry_strategy ?? ($r->suggested_entry_strategy ?? 'unknown')) ?: 'unknown'; return $r; }
    private function setupScanFunnel(Collection $scans, Collection $watch, Collection $plans, Collection $trades, Collection $missed): Collection { return $scans->groupBy('setup_type')->map(fn($r,$s)=>['setup_type'=>$s,'rows'=>$r->count(),'symbols'=>$r->pluck('coindcx_symbol')->unique()->count(),'avg_score'=>$r->avg('final_score'),'selected'=>$r->where('selected_for_watchlist',true)->count(),'selected_pct'=>$this->pct($r->where('selected_for_watchlist',true)->count(),$r->count()),'watchlist'=>$watch->where('setup_type',$s)->count() ?: $r->whereNotNull('candidate_watchlist_id')->count(),'trade_plans'=>$plans->where('setup_type',$s)->count() ?: $r->filter(fn($x)=>(bool)($x->trade_plan_created??false)||($x->trade_plan_id??null))->count(),'sim_trades'=>$trades->where('setup_type',$s)->count(),'avg_actual_change'=>$missed->where('setup_type',$s)->avg('actual_change_24h_percent'),'notes'=>$r->where('selected_for_watchlist',true)->count()===0?'Review selection':'-'])->sortByDesc('rows')->values(); }
    private function setupTradeOutcomes(Collection $trades, Collection $events): Collection { return $trades->groupBy('setup_type')->map(fn($r,$s)=>$this->tradeStats($r,$events)+['setup_type'=>$s])->sortByDesc('trades')->values(); }
    private function setupMissedGainers(Collection $rows): Collection { return $rows->groupBy('setup_type')->map(fn($r,$s)=>['setup_type'=>$s,'actual_gainers'=>$r->count(),'matched'=>$r->where('matched_in_scan',true)->count(),'selected'=>$r->where('selected_for_watchlist',true)->count(),'trade_plans'=>$r->where('trade_plan_created',true)->count(),'sim_trades'=>$r->where('simulated_trade_created',true)->count(),'missed_completely'=>$r->where('miss_type','missed_completely')->count(),'captured_not_selected'=>$r->where('miss_type','captured_not_selected')->count(),'trade_plan_not_triggered'=>$r->where('miss_type','trade_plan_not_triggered')->count(),'avg_change'=>$r->avg('actual_change_24h_percent'),'max_change'=>$r->max('actual_change_24h_percent'),'avg_score'=>$r->avg('best_final_score'),'common_reason'=>$r->pluck('miss_reason')->filter()->countBy()->sortDesc()->keys()->first(),'common_action'=>$r->pluck('action_needed')->filter()->countBy()->sortDesc()->keys()->first()])->sortByDesc('actual_gainers')->values(); }
    private function entryStrategyStats(Collection $plans, Collection $trades, Collection $events): Collection { return $plans->merge($trades)->pluck('entry_strategy')->filter()->unique()->map(function($s) use($plans,$trades,$events){$p=$plans->where('entry_strategy',$s);$t=$trades->where('entry_strategy',$s);return $this->tradeStats($t,$events)+['entry_strategy'=>$s,'trade_plans'=>$p->count(),'triggered_plans'=>$p->filter(fn($x)=>($x->triggered_at??null)||($x->converted_at??null)||($x->simulated_trade_id??null))->count(),'trigger_rate'=>$this->pct($p->filter(fn($x)=>($x->triggered_at??null)||($x->converted_at??null)||($x->simulated_trade_id??null))->count(),$p->count()),'sim_trades'=>$t->count()];})->values(); }
    private function topSymbolsBySetup(Collection $trades): Collection { return $trades->groupBy(fn($t)=>$t->setup_type.'|'.$t->coindcx_symbol)->map(function($r,$k){[$s,$sym]=explode('|',$k,2);$closed=$r->filter(fn($t)=>$this->isClosed($t));return ['setup_type'=>$s,'symbol'=>$sym,'trades'=>$r->count(),'win_rate'=>$this->pct($closed->filter(fn($t)=>(float)($t->final_pnl_percent??0)>0)->count(),$closed->count()),'avg_final'=>$closed->avg('final_pnl_percent'),'avg_max_gain'=>$r->avg('max_gain_percent'),'avg_drawdown'=>$r->avg('max_drawdown_percent'),'best_trade'=>$closed->max('final_pnl_percent') ?? $r->max('max_gain_percent'),'worst_trade'=>$closed->min('final_pnl_percent') ?? $r->min('max_drawdown_percent')];})->sortByDesc('trades')->take(30)->values(); }
    private function tradeStats(Collection $r, Collection $events): array { $closed=$r->filter(fn($t)=>$this->isClosed($t));$wins=$closed->filter(fn($t)=>(float)($t->final_pnl_percent??0)>0)->count();$tp1=$r->whereNotNull('tp1_hit_at')->count()+$this->eventCount($r,$events,'TP1');$tp2=$r->whereNotNull('tp2_hit_at')->count()+$this->eventCount($r,$events,'TP2');$sl=$r->where('status','closed_sl')->count()+$r->whereNotNull('sl_hit_at')->count();return ['trades'=>$r->count(),'open'=>$r->filter(fn($t)=>!$this->isClosed($t))->count(),'closed'=>$closed->count(),'win_rate'=>$this->pct($wins,$closed->count()),'tp1'=>$tp1,'tp1_rate'=>$this->pct($tp1,$r->count()),'tp2'=>$tp2,'tp2_rate'=>$this->pct($tp2,$r->count()),'sl'=>$sl,'sl_rate'=>$this->pct($sl,$r->count()),'trailing_closed'=>$r->where('status','closed_trailing')->count(),'expired'=>$r->where('status','expired')->count(),'avg_current'=>$r->avg('current_pnl_percent'),'avg_final'=>$closed->avg('final_pnl_percent'),'avg_max_gain'=>$r->avg('max_gain_percent'),'avg_drawdown'=>$r->avg('max_drawdown_percent'),'best_final'=>$closed->max('final_pnl_percent') ?? $r->max('max_gain_percent'),'worst_final'=>$closed->min('final_pnl_percent') ?? $r->min('max_drawdown_percent')]; }
    private function summaryStats(Collection $scans, Collection $plans, Collection $trades, Collection $missed, Collection $outcomes, Collection $missedStats, float $min): array { return ['scan_candidates'=>$scans->count(),'distinct_setup_types'=>$scans->pluck('setup_type')->merge($trades->pluck('setup_type'))->merge($missed->pluck('setup_type'))->unique()->count(),'selected'=>$scans->where('selected_for_watchlist',true)->count(),'trade_plans'=>$plans->count(),'sim_trades'=>$trades->count(),'best_avg_final'=>$outcomes->where('closed','>',0)->sortByDesc('avg_final')->first(),'best_tp2'=>$outcomes->where('trades','>',0)->sortByDesc('tp2_rate')->first(),'worst_sl'=>$outcomes->where('trades','>',0)->sortByDesc('sl_rate')->first(),'actual_gainers'=>$missed->count(),'captured_not_selected'=>$missed->where('miss_type','captured_not_selected')->count(),'common_missed_setup'=>$missedStats->sortByDesc('actual_gainers')->first(),'common_trade_setup'=>$outcomes->sortByDesc('trades')->first(),'min_change'=>$min]; }
    private function eventCount(Collection $rows, Collection $events, string $contains): int { return $rows->sum(fn($t)=>collect($events->get($t->id, []))->filter(fn($n,$k)=>str_contains(strtoupper((string)$k),$contains))->sum()); }
    private function isClosed($t): bool { return in_array($t->status ?? '', $this->closedStatuses, true) || ($t->closed_at ?? null) !== null; }
    private function pct(int|float $n, int|float $d): ?float { return $d > 0 ? ($n / $d) * 100 : null; }
    private function severityRank(?string $s): int { return ['critical'=>0,'high'=>1,'medium'=>2,'low'=>3][$s ?? ''] ?? 9; }
}
