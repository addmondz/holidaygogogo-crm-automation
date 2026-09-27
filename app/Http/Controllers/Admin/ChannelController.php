<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ChannelType;
use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\WhatsappTemplate;
use App\Services\Inbox\TemplateSync;
use App\Services\Meta\GraphClient;
use App\Services\Meta\MessengerClient;
use App\Services\Meta\MetaApiException;
use App\Services\Meta\WhatsAppClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Connect WhatsApp numbers and Facebook Pages, and sync WhatsApp templates.
 */
class ChannelController extends Controller
{
    public function index(): Response
    {
        $channels = Channel::query()
            ->withCount(['conversations', 'templates as approved_templates_count' => fn ($q) => $q->where('status', 'APPROVED')])
            ->orderBy('type')
            ->orderBy('name')
            ->get()
            ->map(fn (Channel $channel) => [
                ...$channel->toSummary(),
                'external_id' => $channel->external_id,
                'business_account_id' => $channel->business_account_id,
                'is_active' => $channel->is_active,
                'has_token' => filled($channel->access_token),
                'conversations_count' => $channel->conversations_count,
                'approved_templates_count' => $channel->approved_templates_count,
            ]);

        return Inertia::render('admin/Channels', [
            'channels' => $channels,
            'templates' => WhatsappTemplate::query()
                ->orderBy('name')
                ->get()
                ->map->toPayload(),
            'setup' => [
                'whatsapp_webhook_url' => route('webhooks.whatsapp'),
                'messenger_webhook_url' => route('webhooks.messenger'),
                'verify_token' => config('services.meta.webhook_verify_token'),
                'app_secret_set' => filled(config('services.meta.app_secret')),
                'app_id_set' => filled(config('services.meta.app_id')),
                'demo_mode' => GraphClient::isFake(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Channel::create($this->validated($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Channel added. Click "Test connection" to check it.')]);

        return back();
    }

    public function update(Request $request, Channel $channel): RedirectResponse
    {
        $data = $this->validated($request, $channel);

        // Leave the token field empty to keep the saved one.
        if (blank($data['access_token'] ?? null)) {
            unset($data['access_token']);
        }

        $channel->update($data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Channel saved.')]);

        return back();
    }

    public function destroy(Channel $channel): RedirectResponse
    {
        if ($channel->conversations()->exists()) {
            throw ValidationException::withMessages([
                'channel' => __('This channel has chats. Switch it off instead of deleting it, so the history is kept.'),
            ]);
        }

        $channel->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Channel deleted.')]);

        return back();
    }

    /**
     * Checks the token and subscribes the app to the channel's webhooks.
     */
    public function test(Channel $channel): RedirectResponse
    {
        if (GraphClient::isFake()) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('Demo mode is on (META_FAKE=true), so nothing is sent to Meta.')]);

            return back();
        }

        try {
            if ($channel->isWhatsApp()) {
                $client = new WhatsAppClient($channel);
                $info = $client->phoneNumberInfo();

                if ($channel->business_account_id) {
                    $client->subscribeApp();
                }

                $channel->update(['display_phone' => $info['display_phone_number'] ?? $channel->display_phone]);
                $message = __('Connected to :name (:phone). Quality rating: :quality.', [
                    'name' => $info['verified_name'] ?? 'WhatsApp',
                    'phone' => $info['display_phone_number'] ?? '?',
                    'quality' => $info['quality_rating'] ?? 'unknown',
                ]);
            } else {
                $client = new MessengerClient($channel);
                $info = $client->pageInfo();
                $client->subscribeApp();
                $message = __('Connected to the Facebook Page ":name".', ['name' => $info['name'] ?? $channel->name]);
            }
        } catch (MetaApiException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->friendlyMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    public function syncTemplates(Channel $channel, TemplateSync $sync): RedirectResponse
    {
        if (! $channel->isWhatsApp() || ! $channel->business_account_id) {
            throw ValidationException::withMessages(['channel' => __('Add the WhatsApp Business Account ID to sync templates.')]);
        }

        if (GraphClient::isFake()) {
            Inertia::flash('toast', ['type' => 'info', 'message' => __('Demo mode is on, so templates can\'t be fetched from Meta. Run "php artisan db:seed --class=DemoSeeder" for sample templates.')]);

            return back();
        }

        try {
            $count = $sync->sync($channel);
        } catch (MetaApiException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->friendlyMessage()]);

            return back();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':count template synced.|:count templates synced.', $count)]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Channel $channel = null): array
    {
        $type = $channel?->type->value ?? $request->input('type');

        return $request->validate([
            'type' => [$channel ? 'prohibited' : 'required', new Enum(ChannelType::class)],
            'name' => ['required', 'string', 'max:255'],
            'external_id' => [
                'required', 'string', 'max:64', 'regex:/^\d+$/',
                Rule::unique(Channel::class)->where('type', $type)->ignore($channel?->id),
            ],
            'business_account_id' => ['nullable', 'string', 'max:64', 'regex:/^\d+$/'],
            'display_phone' => ['nullable', 'string', 'max:32'],
            'access_token' => [$channel || GraphClient::isFake() ? 'nullable' : 'required', 'string', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
        ], [
            'external_id.regex' => __('This should be the numeric ID from Meta.'),
            'business_account_id.regex' => __('This should be the numeric ID from Meta.'),
        ]);
    }
}
