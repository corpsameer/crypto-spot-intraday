@extends('layouts.app')

@section('content')
    <header class="page-header">
        <h1>CoinDCX Spot Symbols</h1>
        <p class="subtitle">View the local spot universe and manually refresh it from the CoinDCX public market details API.</p>
    </header>

    <section class="grid" aria-label="Spot symbol summary">
        <article class="card">
            <h2>Total Symbols</h2>
            <div class="status">{{ $symbolCount }}</div>
        </article>
        <article class="card">
            <h2>Active Symbols</h2>
            <div class="status">{{ $activeCount }}</div>
        </article>
        <article class="card">
            <h2>Latest Sync</h2>
            @if ($latestSyncLog)
                <p>{{ $latestSyncLog->checked_at->format('Y-m-d H:i:s') }} UTC</p>
                <span class="badge {{ $latestSyncLog->status === 'ok' ? 'badge-active' : 'badge-inactive' }}">{{ strtoupper($latestSyncLog->status) }}</span>
                <p class="subtitle">{{ $latestSyncLog->message }}</p>
            @else
                <p class="subtitle">No sync has been run yet.</p>
            @endif
        </article>
    </section>

    <section class="card" style="margin-top: 1rem;">
        <div class="actions">
            <form method="POST" action="{{ route('cryptospot.spot-symbols.sync') }}">
                @csrf
                <button class="primary-button" type="submit">Sync CoinDCX Spot Universe</button>
            </form>
            <span class="subtitle">This only updates symbols. It does not poll prices, candles, or trades.</span>
        </div>
    </section>

    <section class="card" style="margin-top: 1rem; overflow-x: auto;">
        <table class="table">
            <thead>
                <tr>
                    <th>Symbol</th>
                    <th>Base</th>
                    <th>Quote</th>
                    <th>Status</th>
                    <th>Min Quantity</th>
                    <th>Last Synced</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($symbols as $symbol)
                    <tr>
                        <td><strong>{{ $symbol->coindcx_symbol }}</strong></td>
                        <td>{{ $symbol->base_asset ?? '—' }}</td>
                        <td>{{ $symbol->quote_asset ?? '—' }}</td>
                        <td><span class="badge {{ $symbol->is_active ? 'badge-active' : 'badge-inactive' }}">{{ $symbol->status }}</span></td>
                        <td>{{ $symbol->min_quantity ?? '—' }}</td>
                        <td>{{ $symbol->last_synced_at?->format('Y-m-d H:i:s') ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6">No symbols synced yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        {{ $symbols->links() }}
    </section>
@endsection
