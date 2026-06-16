<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spot_symbols', function (Blueprint $table): void {
            $table->string('api_pair', 64)->nullable()->after('coindcx_symbol')->index();
        });

        DB::table('spot_symbols')
            ->select(['id', 'raw_payload'])
            ->orderBy('id')
            ->chunkById(500, function ($symbols): void {
                foreach ($symbols as $symbol) {
                    $payload = $this->decodePayload($symbol->raw_payload);
                    $apiPair = $this->resolveApiPair($payload);

                    if ($apiPair !== null) {
                        DB::table('spot_symbols')
                            ->where('id', $symbol->id)
                            ->update(['api_pair' => $apiPair]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('spot_symbols', function (Blueprint $table): void {
            $table->dropIndex(['api_pair']);
            $table->dropColumn('api_pair');
        });
    }

    private function decodePayload(mixed $rawPayload): array
    {
        if (is_array($rawPayload)) {
            return $rawPayload;
        }

        if (! is_string($rawPayload) || trim($rawPayload) === '') {
            return [];
        }

        try {
            $decoded = json_decode($rawPayload, true, flags: JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function resolveApiPair(array $payload): ?string
    {
        foreach (['pair', 'coindcx_name', 'symbol', 'market'] as $key) {
            $value = $payload[$key] ?? null;
            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }
};
