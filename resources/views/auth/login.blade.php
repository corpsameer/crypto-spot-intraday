<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - {{ config('app.name', 'Crypto Spot Intraday') }}</title>
    <style>
        body { align-items: center; background: #f6f8fb; color: #1f2937; display: flex; font-family: Arial, sans-serif; justify-content: center; min-height: 100vh; margin: 0; }
        .login-card { background: #fff; border: 1px solid #e5e7eb; border-radius: .75rem; box-shadow: 0 10px 20px rgba(0,0,0,.06); padding: 2rem; width: min(420px, calc(100% - 2rem)); }
        h1 { margin-top: 0; }
        label { display: block; font-weight: 700; margin-bottom: .4rem; }
        input { border: 1px solid #d1d5db; border-radius: .375rem; box-sizing: border-box; margin-bottom: 1rem; padding: .75rem; width: 100%; }
        button { background: #2563eb; border: 0; border-radius: .375rem; color: #fff; cursor: pointer; font-weight: 700; padding: .75rem 1rem; width: 100%; }
        .error { color: #b91c1c; font-size: .9rem; margin-bottom: 1rem; }
        .subtitle { color: #6b7280; margin-bottom: 1.5rem; }
    </style>
</head>
<body>
    <section class="login-card">
        <h1>Crypto Spot Intraday</h1>
        <p class="subtitle">Sign in to the research dashboard.</p>

        @if ($errors->any())
            <div class="error">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('cryptospot.login.store') }}">
            @csrf
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus>

            <label for="password">Password</label>
            <input id="password" name="password" type="password" required>

            <button type="submit">Login</button>
        </form>
    </section>
</body>
</html>
