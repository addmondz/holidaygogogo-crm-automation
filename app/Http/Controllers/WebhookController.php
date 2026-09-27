<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessMessengerWebhook;
use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Receives Meta webhooks. Work is queued so Meta always gets a fast 200
 * (slow responses make Meta retry and eventually disable the webhook).
 */
class WebhookController extends Controller
{
    /**
     * Meta calls this once with a challenge when you save the webhook URL.
     */
    public function verify(Request $request): Response
    {
        $token = (string) config('services.meta.webhook_verify_token');
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $given = (string) $request->query('hub_verify_token', $request->query('hub.verify_token'));

        abort_unless($token !== '' && $mode === 'subscribe' && hash_equals($token, $given), 403, 'Invalid verify token.');

        // Lets the setup wizard show a green tick once Meta has verified the URL.
        Setting::set('meta.webhook_verified.'.($request->is('webhooks/messenger') ? 'messenger' : 'whatsapp'), now()->toIso8601String());

        return response((string) $request->query('hub_challenge', $request->query('hub.challenge')), 200, [
            'Content-Type' => 'text/plain',
        ]);
    }

    public function whatsapp(Request $request): Response
    {
        $this->verifySignature($request);

        if ($request->input('object') === 'whatsapp_business_account') {
            ProcessWhatsAppWebhook::dispatch($request->json()->all());
        }

        return response('EVENT_RECEIVED');
    }

    public function messenger(Request $request): Response
    {
        $this->verifySignature($request);

        if ($request->input('object') === 'page') {
            ProcessMessengerWebhook::dispatch($request->json()->all());
        }

        return response('EVENT_RECEIVED');
    }

    /**
     * Meta signs every webhook with the app secret (X-Hub-Signature-256).
     */
    private function verifySignature(Request $request): void
    {
        $secret = (string) config('services.meta.app_secret');

        if ($secret === '') {
            if (app()->environment('local', 'testing') || config('services.meta.fake')) {
                return;
            }

            Log::error('Rejected a Meta webhook: META_APP_SECRET is not set.');
            abort(403);
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        abort_unless(hash_equals($expected, (string) $request->header('X-Hub-Signature-256')), 403, 'Invalid signature.');
    }
}
