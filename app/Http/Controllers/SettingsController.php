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
        $settingsByGroup = $settings->allGrouped();
        $preferredGroupOrder = ['scan', 'prefilter', 'monitor', 'trade_plan', 'scanner', 'trade', 'trailing', 'scoring', 'system', 'retention'];
        $groupOrder = collect($preferredGroupOrder)
            ->merge(array_keys($settingsByGroup))
            ->unique()
            ->values()
            ->all();

        return view('settings.index', [
            'settingsByGroup' => $settingsByGroup,
            'groupOrder' => $groupOrder,
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

        $settings = AppSetting::whereIn('key', array_keys($validated['settings'] ?? []))
            ->get()
            ->keyBy('key');

        foreach ($validated['settings'] ?? [] as $key => $value) {
            $setting = $settings->get($key);

            if ($setting?->value_type === 'json' && $value !== null && $value !== '') {
                Validator::make(
                    ['value' => $value],
                    ['value' => ['json']]
                )->validate();
            }

            AppSetting::where('key', $key)
                ->where('is_editable', true)
                ->update(['value' => $value]);
        }

        return back()->with('success', 'Settings updated successfully.');
    }
}
