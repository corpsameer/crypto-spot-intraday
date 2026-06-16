@extends('layouts.app')

@section('content')
    <header class="page-header">
        <h1>Crypto Spot Intraday Gainer Scanner</h1>
        <p class="subtitle">CoinDCX Spot Research &amp; Simulation Tool</p>
    </header>

    <section class="grid" aria-label="Future modules">
        @foreach ($modules as $module)
            <article class="card">
                <h2>{{ $module }}</h2>
                <div class="status">Coming Soon</div>
            </article>
        @endforeach
    </section>
@endsection
