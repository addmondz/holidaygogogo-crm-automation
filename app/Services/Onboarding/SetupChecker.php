<?php

namespace App\Services\Onboarding;

use App\Enums\ChannelType;
use App\Enums\MessageDirection;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Setting;
use App\Models\WhatsappTemplate;
use App\Services\Inbox\TemplateSync;
use App\Services\Meta\GraphClient;
use App\Services\Meta\MessengerClient;
use App\Services\Meta\MetaApiException;
use App\Services\Meta\WhatsAppClient;
use App\Support\MetaSettings;
use Illuminate\Validation\ValidationException;

/**
 * The "Check" behind each wizard step: saves what the admin pasted and
 * confirms with Meta that it works. Throws a ValidationException with a
 * plain-English reason when it doesn't. In demo mode Meta isn't called.
 */
class SetupChecker
{
    public const REQUIRED_PERMISSIONS = ['whatsapp_business_messaging', 'whatsapp_business_management'];

    public const MESSENGER_PERMISSIONS = ['pages_messaging', 'pages_manage_metadata', 'pages_show_list'];

    public function __construct(private readonly TemplateSync $templates) {}

    /**
     * @param  array<string, string|null>  $input
     * @return string What was confirmed, shown to the admin.
     */
    public function run(string $check, array $input): string
    {
        try {
            return match ($check) {
                'app_credentials' => $this->appCredentials($input),
                'system_token' => $this->systemToken($input),
                'whatsapp_number' => $this->whatsappNumber($input),
                'whatsapp_webhook' => $this->webhookVerified('whatsapp'),
                'whatsapp_test' => $this->testMessage(),
                'messenger_page' => $this->messengerPage($input),
                'messenger_webhook' => $this->webhookVerified('messenger'),
                'templates' => $this->syncTemplates(),
            };
        } catch (MetaApiException $e) {
            $this->fail($e->friendlyMessage());
        }
    }

    /**
     * @param  array<string, string|null>  $input
     */
    private function appCredentials(array $input): string
    {
        $appId = $this->required($input, 'app_id', 'the App ID', numeric: true);
        $secret = $this->required($input, 'app_secret', 'the App secret');

        $name = 'your app';

        if (! GraphClient::isFake()) {
            // An "app access token" is simply "{app-id}|{app-secret}".
            $app = (new GraphClient("{$appId}|{$secret}"))->get($appId, ['fields' => 'id,name']);
            $name = $app['name'] ?? $name;
        }

        MetaSettings::set('app_id', $appId);
        MetaSettings::set('app_secret', $secret);

        return GraphClient::isFake()
            ? 'App ID and secret saved (demo mode, not checked with Meta).'
            : "App ID and secret work for \"{$name}\".";
    }

    /**
     * @param  array<string, string|null>  $input
     */
    private function systemToken(array $input): string
    {
        $token = $this->required($input, 'system_token', 'the token');

        if (GraphClient::isFake()) {
            MetaSettings::set('system_token', $token);

            return 'Token saved (demo mode, not checked with Meta).';
        }

        $graph = new GraphClient($token);
        $me = $graph->get('me', ['fields' => 'id,name']);
        $granted = collect($graph->get('me/permissions')['data'] ?? [])
            ->where('status', 'granted')
            ->pluck('permission');

        $missing = collect(self::REQUIRED_PERMISSIONS)->diff($granted);

        if ($missing->isNotEmpty()) {
            $this->fail('The token works but is missing: '.$missing->implode(', ').'. Generate a new token with these permissions ticked.');
        }

        MetaSettings::set('system_token', $token);

        $missingMessenger = collect(self::MESSENGER_PERMISSIONS)->diff($granted);

        return "Token works (system user \"{$me['name']}\")."
            .($missingMessenger->isNotEmpty() ? ' Note: for Messenger it also needs '.$missingMessenger->implode(', ').'.' : '');
    }

    /**
     * @param  array<string, string|null>  $input
     */
    private function whatsappNumber(array $input): string
    {
        $phoneId = $this->required($input, 'phone_number_id', 'the Phone number ID', numeric: true);
        $wabaId = $this->required($input, 'waba_id', 'the WhatsApp Business Account ID', numeric: true);

        $channel = Channel::query()->firstOrNew(['type' => ChannelType::WhatsApp, 'external_id' => $phoneId]);
        $channel->fill([
            'name' => $channel->name ?: 'HolidayGoGoGo WhatsApp',
            'business_account_id' => $wabaId,
            'access_token' => $this->token(),
            'is_active' => true,
        ]);

        if (GraphClient::isFake()) {
            $channel->save();

            return 'WhatsApp number saved (demo mode, not checked with Meta).';
        }

        $client = new WhatsAppClient($channel);
        $info = $client->phoneNumberInfo();
        $client->subscribeApp();

        $channel->display_phone = $info['display_phone_number'] ?? $channel->display_phone;
        $channel->save();

        return sprintf('Connected to %s (%s).', $info['verified_name'] ?? 'your number', $channel->display_phone ?? $phoneId);
    }

    private function webhookVerified(string $channel): string
    {
        $at = Setting::get("meta.webhook_verified.{$channel}");

        if (! $at && ! GraphClient::isFake()) {
            $this->fail('Meta hasn\'t reached the CRM yet. Check the callback URL and verify token were pasted exactly, and that "Verify and save" showed no error.');
        }

        return 'Meta reached the CRM successfully.';
    }

    private function testMessage(): string
    {
        $received = Message::query()
            ->where('direction', MessageDirection::Inbound)
            ->whereHas('conversation.channel', fn ($q) => $q->where('type', ChannelType::WhatsApp))
            ->where('external_id', 'not like', 'wamid.SIM%')
            ->where('external_id', 'not like', 'wamid.DEMO%')
            ->latest('id')
            ->first();

        if (! $received) {
            $this->fail('No WhatsApp message has arrived yet. Wait a few seconds and try again. If it still doesn\'t arrive, check the webhook step and that the queue worker is running.');
        }

        return 'Message received: "'.str($received->body ?? $received->preview())->limit(60).'"';
    }

    /**
     * @param  array<string, string|null>  $input
     */
    private function messengerPage(array $input): string
    {
        $pageId = $this->required($input, 'page_id', 'the Page ID', numeric: true);
        $channel = Channel::query()->firstOrNew(['type' => ChannelType::Messenger, 'external_id' => $pageId]);

        if (GraphClient::isFake()) {
            $channel->fill(['name' => $channel->name ?: 'HolidayGoGoGo Facebook Page', 'access_token' => $this->token(), 'is_active' => true])->save();

            return 'Facebook Page saved (demo mode, not checked with Meta).';
        }

        // Messenger needs the Page's own token, which the system user token can fetch.
        $page = (new GraphClient($this->token()))->get($pageId, ['fields' => 'name,access_token']);

        if (empty($page['access_token'])) {
            $this->fail('The token can\'t manage this Page. In System users → Assign assets, give the CRM system user full control of the Page, then try again.');
        }

        $channel->fill(['name' => $page['name'] ?? 'Facebook Page', 'access_token' => $page['access_token'], 'is_active' => true])->save();

        (new MessengerClient($channel))->subscribeApp();

        return "Connected to the Page \"{$channel->name}\".";
    }

    private function syncTemplates(): string
    {
        $channel = Channel::query()->where('type', ChannelType::WhatsApp)->whereNotNull('business_account_id')->first();

        if (! $channel) {
            $this->fail('Connect your WhatsApp number first.');
        }

        if (! GraphClient::isFake()) {
            $this->templates->sync($channel);
        }

        $approved = WhatsappTemplate::query()->where('channel_id', $channel->id)->where('status', 'APPROVED')->count();

        if ($approved === 0) {
            $this->fail('No approved templates yet. Meta usually approves them within an hour; try again later.');
        }

        return "{$approved} approved template(s) ready to use.";
    }

    private function token(): string
    {
        return MetaSettings::get('system_token') ?? $this->fail('Save the permanent access token first (step "Create a permanent access token").');
    }

    /**
     * @param  array<string, string|null>  $input
     */
    private function required(array $input, string $key, string $label, bool $numeric = false): string
    {
        $value = trim((string) ($input[$key] ?? ''));

        if ($value === '') {
            $this->fail("Paste {$label}.", $key);
        }

        if ($numeric && ! ctype_digit($value)) {
            $this->fail(ucfirst($label).' should contain numbers only.', $key);
        }

        return $value;
    }

    private function fail(string $message, string $field = 'check'): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
