<?php

namespace App\Services;

use App\Models\SpotSymbol;
use App\Models\SystemHealthLog;
use App\Services\CoinDCX\CoinDCXPublicClient;
use Illuminate\Support\Arr;
use Throwable;

class SpotUniverseSyncService
{
    public function __construct(private readonly CoinDCXPublicClient $client)
    {
    }

    public function sync(): array
    {
        $startedAt = now();

        try {
            $markets = $this->client->marketsDetails();
            $synced = 0;
            $skipped = 0;
            $activeSymbols = [];

            foreach ($markets as $market) {
                if (! is_array($market) || ! $this->isSpotMarket($market)) {
                    $skipped++;
                    continue;
                }

                $symbol = $this->symbol($market);

                if ($symbol === null) {
                    $skipped++;
                    continue;
                }

                $activeSymbols[] = $symbol;

                SpotSymbol::updateOrCreate(
                    ['coindcx_symbol' => $symbol],
                    [
                        'base_asset' => $this->stringValue($market, ['target_currency_short_name', 'target_currency']),
                        'quote_asset' => $this->stringValue($market, ['base_currency_short_name', 'base_currency']),
                        'display_name' => $this->displayName($market, $symbol),
                        'status' => strtolower((string) ($market['status'] ?? 'active')),
                        'is_active' => $this->isActive($market),
                        'min_price' => $this->decimalValue($market, ['min_price']),
                        'max_price' => $this->decimalValue($market, ['max_price']),
                        'min_quantity' => $this->decimalValue($market, ['min_quantity']),
                        'quantity_precision' => $this->integerValue($market, ['target_currency_precision', 'quantity_precision']),
                        'price_precision' => $this->integerValue($market, ['base_currency_precision', 'price_precision']),
                        'tick_size' => $this->decimalValue($market, ['min_price_increment', 'tick_size']),
                        'step_size' => $this->decimalValue($market, ['min_quantity_increment', 'step_size']),
                        'last_synced_at' => $startedAt,
                        'raw_payload' => $market,
                    ]
                );

                $synced++;
            }

            $deactivated = SpotSymbol::query()
                ->when($activeSymbols !== [], fn ($query) => $query->whereNotIn('coindcx_symbol', $activeSymbols))
                ->update(['is_active' => false, 'status' => 'inactive', 'last_synced_at' => $startedAt]);

            $result = compact('synced', 'skipped', 'deactivated');
            $this->log('ok', "CoinDCX spot universe sync completed: {$synced} symbols synced.", $result + ['started_at' => $startedAt->toIso8601String()]);

            return $result;
        } catch (Throwable $exception) {
            $this->log('error', 'CoinDCX spot universe sync failed: '.$exception->getMessage(), [
                'exception' => $exception::class,
                'started_at' => $startedAt->toIso8601String(),
            ]);

            throw $exception;
        }
    }

    private function isSpotMarket(array $market): bool
    {
        $type = strtolower((string) ($market['market_type'] ?? $market['type'] ?? $market['product_type'] ?? 'spot'));
        $symbol = $this->symbol($market);

        return $symbol !== null && ! str_contains($type, 'future') && ! str_contains($type, 'margin');
    }

    private function symbol(array $market): ?string
    {
        $symbol = $this->stringValue($market, ['coindcx_name', 'symbol', 'pair']);

        return $symbol !== null ? strtoupper($symbol) : null;
    }

    private function displayName(array $market, string $symbol): string
    {
        return $this->stringValue($market, ['pair', 'symbol', 'coindcx_name']) ?? $symbol;
    }

    private function isActive(array $market): bool
    {
        $status = strtolower((string) ($market['status'] ?? 'active'));

        return in_array($status, ['active', 'live', 'enabled', 'true', '1'], true);
    }

    private function stringValue(array $market, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = Arr::get($market, $key);
            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    private function decimalValue(array $market, array $keys): ?string
    {
        $value = $this->stringValue($market, $keys);

        return is_numeric($value) ? $value : null;
    }

    private function integerValue(array $market, array $keys): ?int
    {
        $value = $this->stringValue($market, $keys);

        return is_numeric($value) ? (int) $value : null;
    }

    private function log(string $status, string $message, array $meta = []): void
    {
        SystemHealthLog::create([
            'service_name' => 'coindcx_spot_universe_sync',
            'status' => $status,
            'message' => $message,
            'checked_at' => now(),
            'meta' => $meta,
        ]);
    }
}
