@extends('layouts.app')

@section('content')
    <header class="page-header">
        <h1>Crypto Spot Intraday Gainer Scanner</h1>
        <p class="subtitle">CoinDCX Spot Research &amp; Simulation Tool</p>
    </header>

    <section class="grid" aria-label="Future modules">
        @foreach ($modules as $module)
            @if ($module === 'Universe Sync')
                <a class="card card-link" href="{{ route('cryptospot.spot-symbols.index') }}">
                    <h2>{{ $module }}</h2>
                    <div class="status">Open</div>
                </a>
            @elseif ($module === 'Settings')
                <a class="card card-link" href="{{ route('cryptospot.settings.index') }}">
                    <h2>{{ $module }}</h2>
                    <div class="status">Open</div>
                </a>
            @elseif ($module === 'Scan Results')
                <a class="card card-link" href="{{ route('cryptospot.scans.latest') }}">
                    <h2>Market Scanner / Latest Scan Results</h2>
                    @if ($latestScanRun)
                        <p>#{{ $latestScanRun->id }} - {{ $latestScanRun->status }}</p>
                        <p>Top: {{ $latestScanRun->top_symbol ?: '-' }} / {{ $latestScanRun->top_score !== null ? number_format((float) $latestScanRun->top_score, 2) : '-' }}</p>
                        <p>Selected candidates: {{ $latestScanRun->scanResults()->where('selected_for_watchlist', true)->count() }}</p>
                    @else
                        <p>No scans yet.</p>
                    @endif
                    <div class="status">Open</div>
                </a>
            @else
                <article class="card">
                    <h2>{{ $module }}</h2>
                    <div class="status">Coming Soon</div>
                </article>
            @endif
        @endforeach
    </section>
@endsection
