<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InboxVisibility;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Support\CrmSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('admin/Settings', [
            'settings' => [
                'visibility' => CrmSettings::visibility()->value,
                'auto_assign' => CrmSettings::autoAssign(),
                'opt_out_keywords' => implode(', ', CrmSettings::optOutKeywords()),
                'opt_in_keywords' => implode(', ', CrmSettings::optInKeywords()),
            ],
            'visibilityOptions' => array_map(
                fn (InboxVisibility $v) => ['value' => $v->value, 'label' => $v->label()],
                InboxVisibility::cases(),
            ),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'visibility' => ['required', new Enum(InboxVisibility::class)],
            'auto_assign' => ['required', 'boolean'],
            'opt_out_keywords' => ['nullable', 'string', 'max:500'],
            'opt_in_keywords' => ['nullable', 'string', 'max:500'],
        ]);

        Setting::set('inbox.visibility', $validated['visibility']);
        Setting::set('assignment.auto_assign', $validated['auto_assign']);
        Setting::set('broadcast.opt_out_keywords', $this->keywords($validated['opt_out_keywords'] ?? ''));
        Setting::set('broadcast.opt_in_keywords', $this->keywords($validated['opt_in_keywords'] ?? ''));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Settings saved.')]);

        return back();
    }

    /**
     * @return list<string>
     */
    private function keywords(string $input): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn (string $word) => mb_strtoupper(trim($word)),
            explode(',', $input),
        ))));
    }
}
