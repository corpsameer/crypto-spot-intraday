<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TradePerformanceController extends Controller
{
    private array $openStatuses = ['active', 'tp1_hit', 'tp2_hit', 'trailing_active'];
    private array $closedStatuses = ['closed_sl', 'closed_trailing', 'closed_tp1', 'closed_tp2', 'expired', 'cancelled', 'error'];

    public function index(Request $request): View
    {
        $latestTradeDate = Schema::hasTable('simulated_trades')
            ? DB::table('simulated_trades')->selectRaw('MAX(COALESCE(entry_triggered_at, created_at)) as latest_date')->value('latest_date')
            : null;

        $defaultTo = $latestTradeDate ? Carbon::parse($latestTradeDate)->toDateString() : now()->toDateString();
        $defaultFrom = $latestTradeDate ? Carbon::parse($latestTradeDate)->subDays(6)->toDateString() : now()->subDays(6)->toDateString();

        $dateFrom = $request->query('from', $defaultFrom);
        $dateTo = $request->query('to', $defaultTo);
        $from = Carbon::parse($dateFrom)->startOfDay();
        $to = Carbon::parse($dateTo)->endOfDay();

        $filters = [
            'from' => $dateFrom,
            'to' => $dateTo,
            'status' => (string) $request->query('status', 'all'),
            'entry_strategy' => (string) $request->query('entry_strategy', 'all'),
            'score_label' => (string) $request->query('score_label', 'all'),
            'symbol' => (string) $request->query('symbol', ''),
            'trade_state' => (string) $request->query('trade_state', 'all'),
            'min_score' => $request->query('min_score'),
            'min_pnl' => $request->query('min_pnl'),
        ];

        $base = DB::table('simulated_trades')
            ->select($this->tradeColumns())
            ->whereBetween(DB::raw('COALESCE(entry_triggered_at, created_at)'), [$from, $to])
            ->when($filters['status'] !== 'all' && $filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when($filters['entry_strategy'] !== 'all' && $filters['entry_strategy'] !== '', fn ($q) => $q->where('entry_strategy', $filters['entry_strategy']))
            ->when($filters['score_label'] !== 'all' && $filters['score_label'] !== '', fn ($q) => $q->where('score_label', $filters['score_label']))
            ->when($filters['symbol'] !== '', fn ($q) => $q->where('coindcx_symbol', 'like', '%'.$filters['symbol'].'%'))
            ->when($filters['trade_state'] === 'open', fn ($q) => $q->whereIn('status', $this->openStatuses))
            ->when($filters['trade_state'] === 'closed', fn ($q) => $q->whereIn('status', $this->closedStatuses))
            ->when($filters['min_score'] !== null && $filters['min_score'] !== '', fn ($q) => $q->where('score', '>=', (float) $filters['min_score']))
            ->when($filters['min_pnl'] !== null && $filters['min_pnl'] !== '', fn ($q) => $q->where(function ($qq) use ($filters) {
                $qq->where('current_pnl_percent', '>=', (float) $filters['min_pnl'])->orWhere('final_pnl_percent', '>=', (float) $filters['min_pnl']);
            }));

        $trades = $base->orderByDesc('updated_at')->get();
        $tradeIds = $trades->pluck('id');
        $events = $tradeIds->isEmpty() ? collect() : DB::table('trade_events')->select('simulated_trade_id', 'event_type')->whereIn('simulated_trade_id', $tradeIds)->get();
        $eventCountsByTrade = $events->groupBy('simulated_trade_id')->map(fn ($rows) => $rows->pluck('event_type')->countBy());

        $summaryStats = $this->summaryStats($trades);
        $outcomeStats = $this->outcomeStats($trades);
        $strategyStats = $this->groupStats($trades, 'entry_strategy', $eventCountsByTrade);
        $scoreLabelStats = $this->groupStats($trades, 'score_label', $eventCountsByTrade);
        $scoreBucketStats = $this->scoreBucketStats($trades, $eventCountsByTrade);
        $symbolStats = $this->symbolStats($trades);
        $eventStats = $events->pluck('event_type')->countBy()->sortKeys();
        $durationStats = $this->durationStats($trades);
        $latestOpenTrades = $trades->whereIn('status', $this->openStatuses)->sortByDesc('updated_at')->take(10)->values();
        $latestClosedTrades = $trades->whereIn('status', $this->closedStatuses)->sortByDesc('closed_at')->take(10)->values();
        $bestTrades = $trades->whereIn('status', $this->closedStatuses)->sortByDesc(fn ($t) => $t->final_pnl_percent ?? $t->max_gain_percent ?? -999999)->take(10)->values();
        $worstTrades = $trades->whereIn('status', $this->closedStatuses)->sortBy(fn ($t) => $t->final_pnl_percent ?? $t->max_drawdown_percent ?? 999999)->take(10)->values();

        return view('analytics.trade-performance', compact('filters', 'summaryStats', 'outcomeStats', 'strategyStats', 'scoreLabelStats', 'scoreBucketStats', 'symbolStats', 'eventStats', 'durationStats', 'latestOpenTrades', 'latestClosedTrades', 'bestTrades', 'worstTrades'));
    }

    private function tradeColumns(): array
    {
        return ['id','coindcx_symbol','status','entry_strategy','score','score_label','entry_price','latest_price','current_pnl_percent','final_pnl_percent','max_gain_percent','max_drawdown_percent','tp1_price','tp2_price','sl_price','current_trailing_sl_price','expires_at','close_price','close_reason','entry_triggered_at','closed_at','created_at','updated_at'];
    }

    private function summaryStats(Collection $trades): array
    {
        $open = $trades->whereIn('status', $this->openStatuses); $closed = $trades->whereIn('status', $this->closedStatuses);
        $winning = $closed->filter(fn ($t) => (float) $t->final_pnl_percent > 0); $losing = $closed->filter(fn ($t) => (float) $t->final_pnl_percent < 0);
        $best = $closed->sortByDesc('final_pnl_percent')->first(); $worst = $closed->sortBy('final_pnl_percent')->first();
        return ['total'=>$trades->count(),'open'=>$open->count(),'closed'=>$closed->count(),'winning'=>$winning->count(),'losing'=>$losing->count(),'win_rate'=>$closed->count()?($winning->count()/$closed->count())*100:null,'open_unrealized'=>$open->sum('current_pnl_percent'),'closed_realized'=>$closed->sum('final_pnl_percent'),'avg_final'=>$closed->count()?$closed->avg('final_pnl_percent'):null,'avg_max_gain'=>$trades->count()?$trades->avg('max_gain_percent'):null,'avg_max_drawdown'=>$trades->count()?$trades->avg('max_drawdown_percent'):null,'best'=>$best,'worst'=>$worst];
    }

    private function outcomeStats(Collection $trades): Collection
    {
        $total = $trades->count();
        return $trades->groupBy(fn ($t) => $t->status ?: 'unknown')->map(function ($rows, $status) use ($total) {
            return ['status'=>$status,'count'=>$rows->count(),'percent'=>$total?($rows->count()/$total)*100:null,'avg_final'=>$rows->avg('final_pnl_percent'),'avg_max_gain'=>$rows->avg('max_gain_percent'),'avg_max_drawdown'=>$rows->avg('max_drawdown_percent'),'avg_holding'=>$this->avgHolding($rows)];
        })->sortByDesc('count')->values();
    }

    private function groupStats(Collection $trades, string $column, Collection $eventCountsByTrade): Collection
    {
        return $trades->groupBy(fn ($t) => $t->{$column} ?: 'unknown')->map(function ($rows, $label) use ($eventCountsByTrade) {
            $closed = $rows->whereIn('status', $this->closedStatuses); $wins = $closed->filter(fn ($t) => (float) $t->final_pnl_percent > 0)->count();
            return ['label'=>$label,'trades'=>$rows->count(),'open'=>$rows->whereIn('status',$this->openStatuses)->count(),'closed'=>$closed->count(),'avg_score'=>$rows->avg('score'),'win_rate'=>$closed->count()?($wins/$closed->count())*100:null,'tp1'=>$this->eventCount($rows,$eventCountsByTrade,'TP1_HIT'),'tp2'=>$this->eventCount($rows,$eventCountsByTrade,'TP2_HIT'),'sl'=>$this->eventCount($rows,$eventCountsByTrade,'SL_HIT'),'trailing_closed'=>$rows->where('status','closed_trailing')->count(),'expired'=>$rows->where('status','expired')->count(),'avg_current'=>$rows->avg('current_pnl_percent'),'avg_final'=>$closed->count()?$closed->avg('final_pnl_percent'):null,'avg_max_gain'=>$rows->avg('max_gain_percent'),'avg_max_drawdown'=>$rows->avg('max_drawdown_percent'),'best_final'=>$closed->max('final_pnl_percent'),'worst_final'=>$closed->min('final_pnl_percent')];
        })->sortByDesc('trades')->values();
    }

    private function scoreBucketStats(Collection $trades, Collection $eventCountsByTrade): Collection
    {
        $buckets = ['0-39'=>[0,39.999],'40-49'=>[40,49.999],'50-59'=>[50,59.999],'60-69'=>[60,69.999],'70-79'=>[70,79.999],'80+'=>[80,999]];
        return collect($buckets)->map(function ($range, $label) use ($trades, $eventCountsByTrade) {
            $rows = $trades->filter(fn ($t) => $t->score !== null && (float)$t->score >= $range[0] && (float)$t->score <= $range[1]); $closed = $rows->whereIn('status',$this->closedStatuses); $wins = $closed->filter(fn($t)=>(float)$t->final_pnl_percent>0)->count();
            return ['label'=>$label,'trades'=>$rows->count(),'closed'=>$closed->count(),'win_rate'=>$closed->count()?($wins/$closed->count())*100:null,'tp1_rate'=>$rows->count()?($this->eventCount($rows,$eventCountsByTrade,'TP1_HIT')/$rows->count())*100:null,'tp2_rate'=>$rows->count()?($this->eventCount($rows,$eventCountsByTrade,'TP2_HIT')/$rows->count())*100:null,'avg_final'=>$closed->count()?$closed->avg('final_pnl_percent'):null,'avg_max_gain'=>$rows->avg('max_gain_percent'),'avg_max_drawdown'=>$rows->avg('max_drawdown_percent')];
        })->values();
    }

    private function symbolStats(Collection $trades): Collection
    { return $trades->groupBy('coindcx_symbol')->map(function($rows,$symbol){$closed=$rows->whereIn('status',$this->closedStatuses);$wins=$closed->filter(fn($t)=>(float)$t->final_pnl_percent>0)->count();return ['symbol'=>$symbol,'trades'=>$rows->count(),'closed'=>$closed->count(),'win_rate'=>$closed->count()?($wins/$closed->count())*100:null,'avg_final'=>$closed->count()?$closed->avg('final_pnl_percent'):null,'avg_max_gain'=>$rows->avg('max_gain_percent'),'avg_max_drawdown'=>$rows->avg('max_drawdown_percent'),'best'=>$closed->max('final_pnl_percent'),'worst'=>$closed->min('final_pnl_percent'),'latest_status'=>$rows->sortByDesc('updated_at')->first()->status ?? '-'];})->sortByDesc('trades')->take(30)->values(); }

    private function durationStats(Collection $trades): array
    { $closed=$trades->whereIn('status',$this->closedStatuses)->filter(fn($t)=>$t->closed_at && ($t->entry_triggered_at || $t->created_at)); $dur=$closed->map(fn($t)=>Carbon::parse($t->entry_triggered_at ?: $t->created_at)->diffInMinutes(Carbon::parse($t->closed_at), false))->filter(fn($m)=>$m>=0); return ['avg'=>$dur->count()?$dur->avg():null,'fastest'=>$dur->count()?$dur->min():null,'longest'=>$dur->count()?$dur->max():null,'by_status'=>$closed->groupBy('status')->map(fn($rows,$s)=>['status'=>$s,'avg'=>$this->avgHolding($rows),'count'=>$rows->count()])->values()]; }
    private function avgHolding(Collection $rows): ?float { $dur=$rows->filter(fn($t)=>$t->closed_at && ($t->entry_triggered_at || $t->created_at))->map(fn($t)=>Carbon::parse($t->entry_triggered_at ?: $t->created_at)->diffInMinutes(Carbon::parse($t->closed_at), false))->filter(fn($m)=>$m>=0); return $dur->count()?$dur->avg():null; }
    private function eventCount(Collection $rows, Collection $eventCountsByTrade, string $type): int { return $rows->sum(fn($t)=>(int)($eventCountsByTrade->get($t->id)[$type] ?? 0)); }
}
