@extends('layouts.app')

@section('content')
    <header class="page-header">
        <h1>Settings</h1>
        <p class="subtitle">View and edit scanner, simulation, scoring, and system settings.</p>
    </header>

    @if (session('success'))
        <div class="alert-success">{{ session('success') }}</div>
    @endif

    <form method="POST" action="{{ route('cryptospot.settings.update') }}">
        @csrf

        @foreach ($groupOrder as $group)
            @php($settings = $settingsByGroup[$group] ?? collect())
            <section class="card settings-group">
                <h2>{{ ucfirst($group) }}</h2>

                @forelse ($settings as $setting)
                    <div class="setting-row">
                        <div>
                            <strong>{{ $setting->label ?? $setting->key }}</strong>
                            <div class="setting-key">{{ $setting->key }}</div>
                            @if ($setting->description)
                                <p class="setting-description">{{ $setting->description }}</p>
                            @endif
                        </div>
                        <input
                            type="text"
                            name="settings[{{ $setting->key }}]"
                            value="{{ old('settings.' . $setting->key, $setting->value) }}"
                            @readonly(! $setting->is_editable)
                        >
                    </div>
                @empty
                    <p class="subtitle">No settings in this group yet.</p>
                @endforelse
            </section>
        @endforeach

        <button class="primary-button" type="submit">Save Settings</button>
    </form>
@endsection
