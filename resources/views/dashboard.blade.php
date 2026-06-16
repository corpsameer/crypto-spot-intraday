@extends('layouts.app')

@section('content')
    <header class="page-header">
        <h1>Crypto Spot Intraday Gainer Scanner</h1>
        <p class="subtitle">CoinDCX Spot Research &amp; Simulation Tool</p>
    </header>

    <section class="grid" aria-label="Future modules">
        @foreach ($modules as $module)
            @if ($module === 'Settings')
                <a class="card card-link" href="{{ route('cryptospot.settings.index') }}">
                    <h2>{{ $module }}</h2>
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
