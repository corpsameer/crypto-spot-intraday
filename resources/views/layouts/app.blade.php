<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Crypto Spot Intraday') }}</title>
    <style>
        :root { color-scheme: light; font-family: Arial, sans-serif; }
        body { margin: 0; background: #f6f8fb; color: #1f2937; }
        .navbar { align-items: center; background: #111827; color: #fff; display: flex; justify-content: space-between; padding: 1rem 1.5rem; }
        .brand { font-size: 1.1rem; font-weight: 700; text-decoration: none; color: #fff; }
        .nav-right { align-items: center; display: flex; gap: 1rem; }
        .nav-link { color: #d1d5db; font-weight: 700; text-decoration: none; }
        .logout-button { background: #ef4444; border: 0; border-radius: .375rem; color: #fff; cursor: pointer; padding: .5rem .75rem; }
        .container { margin: 0 auto; max-width: 1100px; padding: 2rem 1rem; }
        .page-header { margin-bottom: 1.5rem; }
        .subtitle { color: #6b7280; margin-top: .25rem; }
        .grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: .75rem; box-shadow: 0 1px 2px rgba(0,0,0,.04); padding: 1.25rem; }
        .status { color: #2563eb; font-size: .875rem; font-weight: 700; margin-top: .75rem; text-transform: uppercase; }
        .card-link { color: inherit; display: block; text-decoration: none; }
        .alert-error { background: #fee2e2; border: 1px solid #fca5a5; border-radius: .5rem; color: #991b1b; margin-bottom: 1rem; padding: .75rem 1rem; }
        .alert-success { background: #dcfce7; border: 1px solid #86efac; border-radius: .5rem; color: #166534; margin-bottom: 1rem; padding: .75rem 1rem; }
        .settings-group { margin-bottom: 1rem; }
        .setting-row { align-items: start; border-top: 1px solid #e5e7eb; display: grid; gap: 1rem; grid-template-columns: 1fr minmax(180px, 280px); padding: 1rem 0; }
        .setting-key, .setting-description { color: #6b7280; font-size: .875rem; margin: .25rem 0 0; }
        input[readonly] { background: #f3f4f6; color: #6b7280; }
        input { border: 1px solid #d1d5db; border-radius: .375rem; padding: .5rem; width: 100%; }
        .table { border-collapse: collapse; width: 100%; }
        .table th, .table td { border-top: 1px solid #e5e7eb; padding: .75rem; text-align: left; }
        .table th { color: #4b5563; font-size: .8rem; text-transform: uppercase; }
        .badge { border-radius: 999px; display: inline-block; font-size: .75rem; font-weight: 700; padding: .2rem .5rem; }
        .badge-active { background: #dcfce7; color: #166534; }
        .badge-inactive { background: #fee2e2; color: #991b1b; }
        .actions { align-items: center; display: flex; gap: .75rem; }
        .primary-button { background: #2563eb; border: 0; border-radius: .375rem; color: #fff; cursor: pointer; font-weight: 700; padding: .75rem 1rem; }
        .secondary-button { background: #fff; border: 1px solid #d1d5db; border-radius: .375rem; color: #374151; display: inline-block; font-weight: 700; padding: .65rem .9rem; text-decoration: none; }
        .page-header-actions { align-items: center; display: flex; justify-content: space-between; gap: 1rem; }
        .section-card { margin-bottom: 1rem; }
        .metric-grid { margin-bottom: 1rem; }
        .metric-card span, .metric-grid span { color: #6b7280; display: block; font-size: .8rem; text-transform: uppercase; }
        .metric-card strong, .metric-grid strong { display: block; margin-top: .35rem; }
        .run-list { display: flex; flex-direction: column; gap: .4rem; }
        .run-link { border: 1px solid #e5e7eb; border-radius: .5rem; color: #1f2937; padding: .5rem .75rem; text-decoration: none; }
        .run-link.active { background: #eff6ff; border-color: #93c5fd; }
        .filters { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); margin-bottom: 1rem; }
        select { border: 1px solid #d1d5db; border-radius: .375rem; padding: .5rem; width: 100%; }
        .table-wrap { overflow-x: auto; }
        .scanner-table { min-width: 1800px; }
        .badge-green { background: #dcfce7; color: #166534; }
        .badge-red { background: #fee2e2; color: #991b1b; }
        .badge-blue { background: #dbeafe; color: #1e40af; }
        .badge-yellow { background: #fef3c7; color: #92400e; }
        .badge-purple { background: #ede9fe; color: #5b21b6; }
        .badge-orange { background: #ffedd5; color: #9a3412; }
        .muted { color: #6b7280; }
        .text-green { color: #166534; font-weight: 700; }
        .text-red { color: #991b1b; font-weight: 700; }
        .nowrap { white-space: nowrap; }
        .small { font-size: .85rem; }
        .badge-gray { background: #f3f4f6; color: #4b5563; }
        .details-panel { min-width: 220px; }
        .details-panel summary { color: #2563eb; cursor: pointer; font-weight: 700; }
        .details-grid { display: grid; gap: .75rem; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); }
        .quick-actions { flex-wrap: wrap; justify-content: flex-end; }
        h2 { margin-top: 0; }
        .section-card h2 { margin-bottom: .75rem; }
        pre { background: #111827; border-radius: .5rem; color: #e5e7eb; max-width: 520px; overflow: auto; padding: .75rem; white-space: pre-wrap; }
    </style>
</head>
<body>
    <nav class="navbar">
        <a class="brand" href="{{ route('cryptospot.dashboard') }}">Crypto Spot Intraday</a>
        <div class="nav-right">
            <a class="nav-link" href="{{ route('cryptospot.dashboard') }}">Dashboard</a>
            <a class="nav-link" href="{{ route('cryptospot.spot-symbols.index') }}">Spot Symbols</a>
            <a class="nav-link" href="{{ route('cryptospot.scans.latest') }}">Market Scanner</a>
            <a class="nav-link" href="{{ route('cryptospot.watchlist.index') }}">Watchlist</a>
            <a class="nav-link" href="{{ route('cryptospot.trade-plans.index') }}">Trade Plans</a>
            <a class="nav-link" href="{{ route('cryptospot.simulated-trades.index') }}">Simulated Trades</a>
            <a class="nav-link" href="{{ route('cryptospot.daily-gainers.index') }}">Daily Gainers</a>
            <a class="nav-link" href="{{ route('cryptospot.missed-gainers.index') }}">Missed Gainers</a>
            <a class="nav-link" href="{{ route('cryptospot.analytics.scanner-performance') }}">Scanner Analytics</a>
            <a class="nav-link" href="{{ route('cryptospot.analytics.trade-performance') }}">Trade Analytics</a>
            <a class="nav-link" href="{{ route('cryptospot.analytics.score-buckets') }}">Score Buckets</a>
            <a class="nav-link" href="{{ route('cryptospot.analytics.setup-types') }}">Setup Types</a>
            <a class="nav-link" href="{{ route('cryptospot.settings.index') }}">Settings</a>
            <span>{{ auth()->user()->email }}</span>
            <form method="POST" action="{{ route('cryptospot.logout') }}">
                @csrf
                <button class="logout-button" type="submit">Logout</button>
            </form>
        </div>
    </nav>

    <main class="container">
        @if (session('success'))
            <div class="alert-success">{{ session('success') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert-error">{{ $errors->first() }}</div>
        @endif

        @yield('content')
    </main>
</body>
</html>
