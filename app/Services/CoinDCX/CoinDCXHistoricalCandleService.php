<?php

namespace App\Services\CoinDCX;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CoinDCXHistoricalCandleService
{
    private const CHUNK_HOURS = 900;

    /** @return array<int, array{time: CarbonImmutable,open: float,high: float,low: float,close: float}> */
    public function hourly(string $apiPair, CarbonImmutable $from, CarbonImmutable $through, bool $refresh = false): array
    {
        $from = $from->utc()->startOfHour(); $through = $through->utc();
        $all = [];
        for ($start = $from; $start->lt($through); $start = $end) {
            $end = $start->addHours(self::CHUNK_HOURS)->min($through);
            $key = 'coindcx:hourly:'.sha1($apiPair.'|'.$start->getTimestampMs().'|'.$end->getTimestampMs());
            if ($refresh) Cache::forget($key);
            $rows = Cache::remember($key, now()->addDays(30), fn () => $this->fetch($apiPair, $start, $end));
            foreach ($rows as $row) $all[$row['time']->getTimestamp()] = $row;
        }
        ksort($all);
        return array_values($all);
    }

    private function fetch(string $pair, CarbonImmutable $from, CarbonImmutable $through): array
    {
        usleep(150000);
        $response = Http::baseUrl(rtrim((string) config('services.coindcx.market_data_base_url'), '/'))
            ->acceptJson()->timeout(20)->retry(4, fn (int $attempt) => 500 * (2 ** ($attempt - 1)), throw: false)
            ->get('/market_data/candles', ['pair' => $pair, 'interval' => '1h', 'startTime' => $from->getTimestampMs(), 'endTime' => $through->getTimestampMs(), 'limit' => 1000]);
        if (! $response->successful()) throw new RuntimeException("CoinDCX candles request failed ({$response->status()}) for {$pair}");
        $result = [];
        foreach (($response->json() ?: []) as $raw) {
            $timestamp = $raw['time'] ?? $raw['timestamp'] ?? $raw['t'] ?? null;
            $open = $raw['open'] ?? $raw['o'] ?? null; $high = $raw['high'] ?? $raw['h'] ?? null;
            $low = $raw['low'] ?? $raw['l'] ?? null; $close = $raw['close'] ?? $raw['c'] ?? null;
            if (! is_numeric($timestamp) || ! is_numeric($open) || ! is_numeric($high) || ! is_numeric($low) || ! is_numeric($close)) continue;
            $timestamp = (int) $timestamp; if ($timestamp < 100000000000) $timestamp *= 1000;
            $values = array_map('floatval', [$open, $high, $low, $close]);
            if (min($values) <= 0 || $values[1] < $values[2]) continue;
            $result[] = ['time' => CarbonImmutable::createFromTimestampMsUTC($timestamp), 'open' => $values[0], 'high' => $values[1], 'low' => $values[2], 'close' => $values[3]];
        }
        return $result;
    }
}
