<?php

namespace App\Services;

use App\Models\AppSetting;

class AppSettingsService
{
    public function get(string $key, mixed $default = null): mixed
    {
        $setting = AppSetting::where('key', $key)->first();
        return $setting?->value ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        AppSetting::where('key', $key)->update(['value' => $value]);
    }

    public function allGrouped(): array
    {
        return AppSetting::orderBy('group')->orderBy('key')->get()->groupBy('group')->all();
    }
}
