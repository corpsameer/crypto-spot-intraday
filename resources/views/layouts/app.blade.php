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
        .alert-success { background: #dcfce7; border: 1px solid #86efac; border-radius: .5rem; color: #166534; margin-bottom: 1rem; padding: .75rem 1rem; }
        .settings-group { margin-bottom: 1rem; }
        .setting-row { align-items: start; border-top: 1px solid #e5e7eb; display: grid; gap: 1rem; grid-template-columns: 1fr minmax(180px, 280px); padding: 1rem 0; }
        .setting-key, .setting-description { color: #6b7280; font-size: .875rem; margin: .25rem 0 0; }
        input[readonly] { background: #f3f4f6; color: #6b7280; }
        input { border: 1px solid #d1d5db; border-radius: .375rem; padding: .5rem; width: 100%; }
        .primary-button { background: #2563eb; border: 0; border-radius: .375rem; color: #fff; cursor: pointer; font-weight: 700; padding: .75rem 1rem; }
    </style>
</head>
<body>
    <nav class="navbar">
        <a class="brand" href="{{ route('cryptospot.dashboard') }}">Crypto Spot Intraday</a>
        <div class="nav-right">
            <a class="nav-link" href="{{ route('cryptospot.settings.index') }}">Settings</a>
            <span>{{ auth()->user()->email }}</span>
            <form method="POST" action="{{ route('cryptospot.logout') }}">
                @csrf
                <button class="logout-button" type="submit">Logout</button>
            </form>
        </div>
    </nav>

    <main class="container">
        @yield('content')
    </main>
</body>
</html>
