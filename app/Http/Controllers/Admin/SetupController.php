<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Setting;
use App\Services\Meta\GraphClient;
use App\Services\Onboarding\SetupChecker;
use App\Services\Onboarding\Steps;
use App\Support\MetaSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Step-by-step Meta setup: from creating a Facebook Page to going live.
 */
class SetupController extends Controller
{
    private const PROGRESS = 'onboarding.progress';

    public function show(): Response
    {
        // The verify token is just a shared password with Meta; make one if missing.
        if (blank(config('services.meta.webhook_verify_token'))) {
            MetaSettings::set('verify_token', Str::random(32));
        }

        $progress = $this->progress();
        $values = $this->placeholders();

        $steps = collect(Steps::all())->map(fn (array $step) => [
            ...$step,
            'intro' => $this->fill($step['intro'], $values),
            'instructions' => array_map(fn ($line) => $this->fill($line, $values), $step['instructions']),
            'links' => array_map(fn ($link) => [...$link, 'url' => $this->fill($link['url'], $values)], $step['links']),
            'fields' => array_map(fn ($field) => [...$field, 'value' => ($field['secret'] ?? false) ? '' : $this->currentValue($field['name'])], $step['fields']),
            'status' => $progress[$step['key']]['status'] ?? 'todo',
            'result' => $progress[$step['key']]['result'] ?? null,
            'has_saved_secret' => collect($step['fields'])->contains(fn ($f) => ($f['secret'] ?? false) && MetaSettings::get($f['name'])),
        ])->values();

        return Inertia::render('admin/Setup', [
            'steps' => $steps,
            'copyValues' => [
                'whatsapp_webhook' => $values['whatsapp_webhook'],
                'messenger_webhook' => $values['messenger_webhook'],
                'verify_token' => $values['verify_token'],
                'privacy_url' => $values['privacy_url'],
            ],
            'demoMode' => GraphClient::isFake(),
            'httpsOk' => str_starts_with((string) config('app.url'), 'https://'),
        ]);
    }

    /**
     * "Continue": run the step's check (if any), then mark it done or waiting.
     */
    public function complete(Request $request, string $step, SetupChecker $checker): RedirectResponse
    {
        $definition = collect(Steps::all())->firstWhere('key', $step) ?? abort(404);

        $input = $request->validate([
            ...collect($definition['fields'])->mapWithKeys(fn ($f) => [$f['name'] => ['nullable', 'string', 'max:2048']])->all(),
            'waiting' => ['sometimes', 'boolean'],
        ]);

        // A saved secret can be kept by leaving the field empty.
        foreach ($definition['fields'] as $field) {
            if (($field['secret'] ?? false) && blank($input[$field['name']] ?? null)) {
                $input[$field['name']] = MetaSettings::get($field['name']);
            }
        }

        $result = $definition['check'] ? $checker->run($definition['check'], $input) : null;
        $status = $definition['wait'] && $request->boolean('waiting') ? 'waiting' : 'done';

        $this->save($step, ['status' => $status, 'result' => $result, 'at' => now()->toIso8601String()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $result ?? ($status === 'waiting' ? __('Marked as waiting for Meta.') : __('Step completed.'))]);

        return back();
    }

    public function undo(string $step): RedirectResponse
    {
        abort_unless(collect(Steps::all())->contains('key', $step), 404);

        $this->save($step, null);

        return back();
    }

    /**
     * @return array<string, array{status: string, result: ?string, at: string}>
     */
    private function progress(): array
    {
        return (array) Setting::get(self::PROGRESS, []);
    }

    /**
     * @param  array<string, mixed>|null  $entry
     */
    private function save(string $step, ?array $entry): void
    {
        $progress = $this->progress();

        if ($entry === null) {
            unset($progress[$step]);
        } else {
            $progress[$step] = $entry;
        }

        Setting::set(self::PROGRESS, $progress);
    }

    /**
     * @return array<string, string>
     */
    private function placeholders(): array
    {
        $whatsapp = Channel::query()->where('type', 'whatsapp')->whereNotNull('business_account_id')->first();

        return [
            'app_id' => (string) (config('services.meta.app_id') ?: ''),
            'waba_id' => (string) ($whatsapp->business_account_id ?? ''),
            'display_phone' => (string) ($whatsapp->display_phone ?? ''),
            'whatsapp_webhook' => route('webhooks.whatsapp'),
            'messenger_webhook' => route('webhooks.messenger'),
            'verify_token' => (string) config('services.meta.webhook_verify_token'),
            'privacy_url' => route('privacy'),
        ];
    }

    /**
     * @param  array<string, string>  $values
     */
    private function fill(string $text, array $values): string
    {
        $text = preg_replace_callback('/\{(\w+)\}/', fn ($m) => array_key_exists($m[1], $values) && $values[$m[1]] !== '' ? $values[$m[1]] : $m[0], $text);

        // Links that still need the app ID fall back to the app list.
        return str_contains($text, 'developers.facebook.com/apps/{app_id}') ? 'https://developers.facebook.com/apps/' : $text;
    }

    private function currentValue(string $field): string
    {
        return (string) match ($field) {
            'app_id' => config('services.meta.app_id'),
            'phone_number_id' => Channel::query()->where('type', 'whatsapp')->value('external_id'),
            'waba_id' => Channel::query()->where('type', 'whatsapp')->value('business_account_id'),
            'page_id' => Channel::query()->where('type', 'messenger')->value('external_id'),
            default => '',
        };
    }
}
