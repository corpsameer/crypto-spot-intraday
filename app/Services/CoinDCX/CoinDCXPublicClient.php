<?php

namespace App\Services\CoinDCX;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class CoinDCXPublicClient
{
    public function marketsDetails(): array
    {
        return $this->client()
            ->get('/exchange/v1/markets_details')
            ->throw()
            ->json();
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.coindcx.public_base_url'), '/'))
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 500);
    }
}
