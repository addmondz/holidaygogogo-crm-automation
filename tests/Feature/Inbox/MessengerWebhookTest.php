<?php

namespace Tests\Feature\Inbox;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MetaPayloads;
use Tests\TestCase;

class MessengerWebhookTest extends TestCase
{
    use MetaPayloads, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.meta.app_secret' => 'test-secret', 'services.meta.app_id' => '777', 'services.meta.fake' => false]);
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    public function test_an_incoming_messenger_message_creates_a_lead_with_their_facebook_name()
    {
        Http::fake([
            'graph.facebook.com/*/PSID123*' => Http::response(['first_name' => 'Mei', 'last_name' => 'Ling', 'profile_pic' => 'https://pic.example/mei.jpg']),
        ]);

        $channel = Channel::factory()->messenger()->create(['external_id' => '5555']);

        $this->postSignedWebhook('/webhooks/messenger', $this->messengerPayload($channel, [[
            'sender' => ['id' => 'PSID123'],
            'recipient' => ['id' => '5555'],
            'timestamp' => now()->getTimestampMs(),
            'message' => ['mid' => 'm_1', 'text' => 'Any Korea packages in December?'],
        ]]))->assertOk();

        $contact = Contact::sole();
        $this->assertSame('Mei Ling', $contact->name);
        $this->assertNull($contact->phone);
        $this->assertSame('https://pic.example/mei.jpg', $contact->avatar_url);

        $this->assertSame('PSID123', Conversation::sole()->external_id);
        $this->assertSame('Any Korea packages in December?', Message::sole()->body);
    }

    public function test_photos_from_messenger_are_saved_as_separate_bubbles()
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([], 400),
            'cdn.fb.example/*' => Http::response('IMG', 200, ['Content-Type' => 'image/png']),
        ]);

        $channel = Channel::factory()->messenger()->create();

        $this->postSignedWebhook('/webhooks/messenger', $this->messengerPayload($channel, [[
            'sender' => ['id' => 'PSID1'],
            'recipient' => ['id' => $channel->external_id],
            'timestamp' => now()->getTimestampMs(),
            'message' => [
                'mid' => 'm_2',
                'text' => 'Like these',
                'attachments' => [
                    ['type' => 'image', 'payload' => ['url' => 'https://cdn.fb.example/a.png']],
                    ['type' => 'image', 'payload' => ['url' => 'https://cdn.fb.example/b.png']],
                ],
            ],
        ]]));

        $messages = Message::orderBy('id')->get();
        $this->assertCount(3, $messages);
        $this->assertSame(['m_2', 'm_2#1', 'm_2#2'], $messages->pluck('external_id')->all());
        $this->assertSame(MessageType::Image, $messages[1]->type);
        $this->assertNotEmpty($messages[1]->media['path']);
    }

    public function test_replies_from_the_page_inbox_are_recorded_but_our_own_echoes_are_skipped()
    {
        $channel = Channel::factory()->messenger()->create();
        Conversation::factory()->for($channel)->create(['external_id' => 'PSID9']);

        $echo = fn (string $mid, ?string $appId) => [
            'sender' => ['id' => $channel->external_id],
            'recipient' => ['id' => 'PSID9'],
            'timestamp' => now()->getTimestampMs(),
            'message' => array_filter(['mid' => $mid, 'is_echo' => true, 'app_id' => $appId, 'text' => 'Reply from page inbox']),
        ];

        $this->postSignedWebhook('/webhooks/messenger', $this->messengerPayload($channel, [$echo('m_ours', '777'), $echo('m_page', '263902037430900')]));

        $message = Message::sole();
        $this->assertSame('m_page', $message->external_id);
        $this->assertSame(MessageDirection::Outbound, $message->direction);
    }

    public function test_read_receipts_mark_earlier_messages_as_read()
    {
        $channel = Channel::factory()->messenger()->create();
        $conversation = Conversation::factory()->for($channel)->create(['external_id' => 'PSID7']);
        $old = Message::factory()->for($conversation)->outbound()->create(['created_at' => now()->subMinutes(5)]);
        $new = Message::factory()->for($conversation)->outbound()->create(['created_at' => now()->addMinute()]);

        $this->postSignedWebhook('/webhooks/messenger', $this->messengerPayload($channel, [[
            'sender' => ['id' => 'PSID7'],
            'recipient' => ['id' => $channel->external_id],
            'read' => ['watermark' => now()->getTimestampMs()],
        ]]));

        $this->assertSame(MessageStatus::Read, $old->fresh()->status);
        $this->assertSame(MessageStatus::Sent, $new->fresh()->status);
    }

    public function test_agents_reply_on_messenger_with_the_page_token()
    {
        Http::fake(['graph.facebook.com/*/messages' => Http::response(['recipient_id' => 'PSID5', 'message_id' => 'm_sent'])]);

        $channel = Channel::factory()->messenger()->create(['external_id' => '4444', 'access_token' => 'page-token']);
        $conversation = Conversation::factory()->for($channel)->create(['external_id' => 'PSID5']);

        $this->actingAs(User::factory()->admin()->create())
            ->postJson(route('conversations.messages.store', $conversation), ['body' => 'Yes, we have!'])
            ->assertCreated();

        $this->assertSame('m_sent', Message::where('direction', 'outbound')->sole()->external_id);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://graph.facebook.com/v23.0/4444/messages'
            && $request['recipient']['id'] === 'PSID5'
            && $request['messaging_type'] === 'RESPONSE'
            && $request['message']['text'] === 'Yes, we have!');
    }

    public function test_human_agent_tag_allows_replies_for_seven_days_when_enabled()
    {
        Http::fake(['*' => Http::response(['message_id' => 'm_late'])]);

        $channel = Channel::factory()->messenger()->create();
        $conversation = Conversation::factory()->for($channel)->create(['last_inbound_at' => now()->subDays(3)]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->postJson(route('conversations.messages.store', $conversation), ['body' => 'Hi'])->assertUnprocessable();

        config(['services.meta.messenger_human_agent' => true]);

        $this->actingAs($admin)->postJson(route('conversations.messages.store', $conversation), ['body' => 'Hi'])->assertCreated();

        Http::assertSent(fn (Request $request) => $request['messaging_type'] === 'MESSAGE_TAG' && $request['tag'] === 'HUMAN_AGENT');
    }
}
