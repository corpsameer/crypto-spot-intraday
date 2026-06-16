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
        .logout-button { background: #ef4444; border: 0; border-radius: .375rem; color: #fff; cursor: pointer; padding: .5rem .75rem; }
        .container { margin: 0 auto; max-width: 1100px; padding: 2rem 1rem; }
        .page-header { margin-bottom: 1.5rem; }
        .subtitle { color: #6b7280; margin-top: .25rem; }
        .grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
        .card { background: #fff; border: 1px solid #e5e7eb; border-radius: .75rem; box-shadow: 0 1px 2px rgba(0,0,0,.04); padding: 1.25rem; }
        .status { color: #2563eb; font-size: .875rem; font-weight: 700; margin-top: .75rem; text-transform: uppercase; }
    </style>
</head>
<body>
    <nav class="navbar">
        <a class="brand" href="{{ route('cryptospot.dashboard') }}">Crypto Spot Intraday</a>
        <div class="nav-right">
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
