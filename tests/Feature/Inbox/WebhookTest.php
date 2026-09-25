<?php

namespace Tests\Feature\Inbox;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Inbox\MediaStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MetaPayloads;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use MetaPayloads, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meta.app_secret' => 'test-secret',
            'services.meta.webhook_verify_token' => 'my-verify-token',
            'services.meta.fake' => false,
        ]);

        Http::preventStrayRequests();
    }

    public function test_meta_can_verify_the_webhook_url()
    {
        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=my-verify-token&hub.challenge=12345')
            ->assertOk()
            ->assertSeeText('12345');

        $this->get('/webhooks/messenger?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=12345')
            ->assertForbidden();
    }

    public function test_webhooks_with_a_bad_signature_are_rejected()
    {
        $channel = Channel::factory()->create();
        $payload = $this->whatsappTextPayload($channel, '60123456789', 'Hi');

        $this->postSignedWebhook('/webhooks/whatsapp', $payload, 'wrong-secret')->assertForbidden();
        $this->postJson('/webhooks/whatsapp', $payload)->assertForbidden();

        $this->assertSame(0, Message::count());
    }

    public function test_an_incoming_whatsapp_message_creates_a_lead_and_a_chat()
    {
        $channel = Channel::factory()->create();

        $this->postSignedWebhook('/webhooks/whatsapp', $this->whatsappTextPayload($channel, '60123456789', 'Hi, how much is the Japan tour?', 'Kerry Tan'))
            ->assertOk();

        $contact = Contact::sole();
        $this->assertSame('Kerry Tan', $contact->name);
        $this->assertSame('60123456789', $contact->phone);

        $conversation = Conversation::sole();
        $this->assertSame('60123456789', $conversation->external_id);
        $this->assertSame(1, $conversation->unread_count);
        $this->assertTrue($conversation->isWindowOpen());
        $this->assertSame('Hi, how much is the Japan tour?', $conversation->last_message_preview);

        $message = Message::sole();
        $this->assertSame(MessageDirection::Inbound, $message->direction);
        $this->assertSame('Hi, how much is the Japan tour?', $message->body);
    }

    public function test_duplicate_webhooks_are_ignored()
    {
        $channel = Channel::factory()->create();
        $payload = $this->whatsappTextPayload($channel, '60123456789', 'Hello', id: 'wamid.SAME');

        $this->postSignedWebhook('/webhooks/whatsapp', $payload)->assertOk();
        $this->postSignedWebhook('/webhooks/whatsapp', $payload)->assertOk();

        $this->assertSame(1, Message::count());
        $this->assertSame(1, Conversation::sole()->unread_count);
    }

    public function test_messages_for_an_existing_contact_reuse_the_contact()
    {
        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create(['phone' => '60123456789', 'name' => 'Imported Name']);

        $this->postSignedWebhook('/webhooks/whatsapp', $this->whatsappTextPayload($channel, '60123456789', 'Hi', 'WhatsApp Name'));

        $this->assertSame(1, Contact::count());
        $this->assertSame('Imported Name', $contact->fresh()->name);
        $this->assertSame($contact->id, Conversation::sole()->contact_id);
    }

    public function test_incoming_photos_are_downloaded()
    {
        $channel = Channel::factory()->create();

        Http::fake([
            'graph.facebook.com/*/media-123*' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp/media-123', 'mime_type' => 'image/jpeg']),
            'lookaside.fbsbx.com/*' => Http::response('JPEGDATA', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->postSignedWebhook('/webhooks/whatsapp', $this->whatsappMessagePayload($channel, [
            'from' => '60123456789',
            'type' => 'image',
            'image' => ['id' => 'media-123', 'mime_type' => 'image/jpeg', 'caption' => 'This hotel?'],
        ]));

        $message = Message::sole();
        $this->assertSame(MessageType::Image, $message->type);
        $this->assertSame('This hotel?', $message->body);
        $this->assertNotEmpty($message->media['path']);
        $this->assertSame('JPEGDATA', MediaStore::get($message->media['path']));
        $this->assertSame('📷 Photo: This hotel?', Conversation::sole()->last_message_preview);
    }

    public function test_delivery_and_read_receipts_update_message_status_in_order()
    {
        $channel = Channel::factory()->create();
        $message = Message::factory()
            ->for(Conversation::factory()->for($channel))
            ->outbound()
            ->create(['external_id' => 'wamid.OUT1']);

        $this->postSignedWebhook('/webhooks/whatsapp', $this->whatsappStatusPayload($channel, 'wamid.OUT1', 'read'));
        $this->assertSame(MessageStatus::Read, $message->fresh()->status);

        // A late "delivered" receipt must not move it back.
        $this->postSignedWebhook('/webhooks/whatsapp', $this->whatsappStatusPayload($channel, 'wamid.OUT1', 'delivered'));
        $this->assertSame(MessageStatus::Read, $message->fresh()->status);
    }

    public function test_failed_receipts_store_the_reason()
    {
        $channel = Channel::factory()->create();
        $message = Message::factory()
            ->for(Conversation::factory()->for($channel))
            ->outbound()
            ->create(['external_id' => 'wamid.OUT2']);

        $this->postSignedWebhook('/webhooks/whatsapp', $this->whatsappStatusPayload($channel, 'wamid.OUT2', 'failed', [
            'errors' => [['code' => 131047, 'title' => 'Re-engagement message', 'error_data' => ['details' => 'More than 24 hours have passed.']]],
        ]));

        $message->refresh();
        $this->assertSame(MessageStatus::Failed, $message->status);
        $this->assertStringContainsString('More than 24 hours', $message->error);
        $this->assertStringContainsString('131047', $message->error);
    }

    public function test_webhooks_for_unknown_numbers_are_ignored()
    {
        $channel = Channel::factory()->make(['external_id' => '999']);

        $this->postSignedWebhook('/webhooks/whatsapp', $this->whatsappTextPayload($channel, '60123456789', 'Hi'))->assertOk();

        $this->assertSame(0, Message::count());
    }

    public function test_click_to_whatsapp_ad_leads_are_marked()
    {
        $channel = Channel::factory()->create();

        $this->postSignedWebhook('/webhooks/whatsapp', $this->whatsappMessagePayload($channel, [
            'from' => '60123456789',
            'type' => 'text',
            'text' => ['body' => 'I saw your ad'],
            'referral' => ['source_url' => 'https://fb.me/ad', 'source_type' => 'ad', 'headline' => 'Bali 5D4N'],
        ]));

        $this->assertSame('whatsapp_ad', Contact::sole()->source);
        $this->assertSame('Bali 5D4N', Message::sole()->meta['referral']['headline']);
    }

    public function test_messages_sent_from_the_whatsapp_business_app_are_recorded()
    {
        $channel = Channel::factory()->create();
        $payload = $this->whatsappPayload($channel, [
            'message_echoes' => [[
                'from' => '60300000000',
                'to' => '60123456789',
                'id' => 'wamid.ECHO1',
                'timestamp' => (string) now()->timestamp,
                'type' => 'text',
                'text' => ['body' => 'Sent from my phone'],
            ]],
        ]);
        $payload['entry'][0]['changes'][0]['field'] = 'smb_message_echoes';

        $this->postSignedWebhook('/webhooks/whatsapp', $payload)->assertOk();

        $message = Message::sole();
        $this->assertSame(MessageDirection::Outbound, $message->direction);
        $this->assertSame('Sent from my phone', $message->body);
        $this->assertTrue($message->meta['sent_outside_crm']);
        $this->assertSame(0, Conversation::sole()->unread_count);
    }
}
