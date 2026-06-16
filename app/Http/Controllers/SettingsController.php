<?php

namespace App\Http\Controllers;

use App\Models\AppSetting;
use App\Services\AppSettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function index(AppSettingsService $settings): View
    {
        return view('settings.index', [
            'settingsByGroup' => $settings->allGrouped(),
            'groupOrder' => ['scanner', 'trade', 'trailing', 'scoring', 'system'],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'settings' => ['array'],
            'settings.*' => ['nullable', 'string'],
        ]);

        Validator::make(
            ['keys' => array_keys($validated['settings'] ?? [])],
            ['keys.*' => [Rule::exists('app_settings', 'key')]]
        )->validate();

        foreach ($validated['settings'] ?? [] as $key => $value) {
            AppSetting::where('key', $key)
                ->where('is_editable', true)
                ->update(['value' => $value]);
        }

        return back()->with('success', 'Settings updated successfully.');
    }
}
