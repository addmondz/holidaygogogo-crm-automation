<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Conversation;
use App\Services\Meta\GraphClient;
use App\Services\Webhooks\MessengerWebhookHandler;
use App\Services\Webhooks\WhatsAppWebhookHandler;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Demo mode only: pretend a customer sent a message, going through the same
 * code as a real Meta webhook. Handy for training agents before go-live.
 */
class SimulatorController extends Controller
{
    public function store(Request $request, WhatsAppWebhookHandler $whatsapp, MessengerWebhookHandler $messenger): RedirectResponse
    {
        abort_unless(GraphClient::isFake(), 404);

        $validated = $request->validate([
            'channel_id' => ['required', 'integer', 'exists:channels,id'],
            'name' => ['required', 'string', 'max:100'],
            'from' => ['required', 'string', 'max:32'],
            'text' => ['required', 'string', 'max:2000'],
        ]);

        $channel = Channel::findOrFail($validated['channel_id']);

        if ($channel->isWhatsApp()) {
            $from = Phone::normalize($validated['from']);

            if (! $from) {
                throw ValidationException::withMessages(['from' => __('Enter a phone number, e.g. 0123456789.')]);
            }

            $whatsapp->handle([
                'object' => 'whatsapp_business_account',
                'entry' => [[
                    'id' => $channel->business_account_id ?? '0',
                    'changes' => [[
                        'field' => 'messages',
                        'value' => [
                            'messaging_product' => 'whatsapp',
                            'metadata' => ['phone_number_id' => $channel->external_id],
                            'contacts' => [['wa_id' => $from, 'profile' => ['name' => $validated['name']]]],
                            'messages' => [[
                                'from' => $from,
                                'id' => 'wamid.SIM'.Str::upper(Str::random(24)),
                                'timestamp' => (string) now()->timestamp,
                                'type' => 'text',
                                'text' => ['body' => $validated['text']],
                            ]],
                        ],
                    ]],
                ]],
            ]);
        } else {
            $psid = preg_replace('/\D/', '', $validated['from']) ?: (string) random_int(1_000_000, 9_999_999);

            $messenger->handle([
                'object' => 'page',
                'entry' => [[
                    'id' => $channel->external_id,
                    'messaging' => [[
                        'sender' => ['id' => $psid],
                        'recipient' => ['id' => $channel->external_id],
                        'timestamp' => now()->getTimestampMs(),
                        'message' => ['mid' => 'm_SIM'.Str::random(24), 'text' => $validated['text']],
                    ]],
                ]],
            ]);

            // Messenger webhooks don't include the name; real ones are looked up from Meta.
            $contact = Conversation::query()
                ->where('channel_id', $channel->id)
                ->where('external_id', $psid)
                ->first()?->contact;

            if ($contact && ! $contact->name) {
                $contact->update(['name' => $validated['name']]);
            }
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Simulated message received.')]);

        return back();
    }
}
