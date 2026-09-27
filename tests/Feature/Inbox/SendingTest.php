<?php

namespace Tests\Feature\Inbox;

use App\Enums\ContactStatus;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\QuickReply;
use App\Models\User;
use App\Models\WhatsappTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SendingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.meta.fake' => false]);
        Storage::fake('local');
        Http::preventStrayRequests();
    }

    public function test_agent_can_reply_with_text_within_the_24_hour_window()
    {
        Http::fake([
            'graph.facebook.com/*/messages' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['input' => '60123456789', 'wa_id' => '60123456789']],
                'messages' => [['id' => 'wamid.SENT1']],
            ]),
        ]);

        $agent = User::factory()->create();
        $channel = Channel::factory()->create(['external_id' => '1111', 'access_token' => 'token-abc']);
        $conversation = Conversation::factory()->for($channel)->create(['external_id' => '60123456789', 'assigned_user_id' => $agent->id]);

        $this->actingAs($agent)
            ->postJson(route('conversations.messages.store', $conversation), ['body' => 'Hi! The Japan tour is RM4,999.'])
            ->assertCreated()
            ->assertJsonPath('message.body', 'Hi! The Japan tour is RM4,999.');

        $message = Message::sole();
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame('wamid.SENT1', $message->external_id);
        $this->assertSame($agent->id, $message->user_id);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://graph.facebook.com/v23.0/1111/messages'
            && $request->hasHeader('Authorization', 'Bearer token-abc')
            && $request['to'] === '60123456789'
            && $request['type'] === 'text'
            && $request['text']['body'] === 'Hi! The Japan tour is RM4,999.');

        $this->assertSame(ContactStatus::Contacted, $conversation->contact->fresh()->status);
    }

    public function test_replying_to_an_unassigned_chat_claims_it()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.X']]])]);

        $agent = User::factory()->create();
        $conversation = Conversation::factory()->create();

        $this->actingAs($agent)->postJson(route('conversations.messages.store', $conversation), ['body' => 'Hello'])->assertCreated();

        $this->assertSame($agent->id, $conversation->fresh()->assigned_user_id);
        $this->assertDatabaseHas('messages', ['type' => 'event', 'body' => "{$agent->name} took this chat"]);
    }

    public function test_free_text_is_blocked_after_the_24_hour_window()
    {
        Http::fake();

        $agent = User::factory()->admin()->create();
        $conversation = Conversation::factory()->windowClosed()->create();

        $this->actingAs($agent)
            ->postJson(route('conversations.messages.store', $conversation), ['body' => 'Hello?'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');

        Http::assertNothingSent();
    }

    public function test_meta_errors_mark_the_message_as_failed()
    {
        Http::fake([
            '*' => Http::response(['error' => [
                'message' => '(#131030) Recipient phone number not in allowed list',
                'code' => 131030,
                'error_data' => ['details' => 'Recipient phone number not in allowed list: Add recipient phone number to recipient list and try again.'],
            ]], 400),
        ]);

        $agent = User::factory()->admin()->create();
        $conversation = Conversation::factory()->create();

        $this->actingAs($agent)->postJson(route('conversations.messages.store', $conversation), ['body' => 'Hi'])->assertCreated();

        $message = Message::where('direction', 'outbound')->sole();
        $this->assertSame(MessageStatus::Failed, $message->status);
        $this->assertStringContainsString('allowed list', $message->error);
    }

    public function test_agent_can_send_a_file()
    {
        Http::fake([
            'graph.facebook.com/*/media' => Http::response(['id' => 'uploaded-media-1']),
            'graph.facebook.com/*/messages' => Http::response(['messages' => [['id' => 'wamid.DOC']]]),
        ]);

        $agent = User::factory()->admin()->create();
        $conversation = Conversation::factory()->create();

        $this->actingAs($agent)->post(route('conversations.messages.store', $conversation), [
            'body' => 'Here is the itinerary',
            'file' => UploadedFile::fake()->create('Japan-Itinerary.pdf', 120, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $message = Message::where('direction', 'outbound')->sole();
        $this->assertSame(MessageType::Document, $message->type);
        $this->assertSame(MessageStatus::Sent, $message->status);
        $this->assertSame('Japan-Itinerary.pdf', $message->media['filename']);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/messages')
            && $request['type'] === 'document'
            && $request['document']['id'] === 'uploaded-media-1'
            && $request['document']['caption'] === 'Here is the itinerary'
            && $request['document']['filename'] === 'Japan-Itinerary.pdf');
    }

    public function test_quick_reply_attachments_are_sent_with_the_message()
    {
        Http::fake(['*' => Http::response(['id' => 'media-1', 'messages' => [['id' => 'wamid.QR']]])]);

        $agent = User::factory()->admin()->create();
        $conversation = Conversation::factory()->create();
        Storage::disk('local')->put('quick-replies/brochure.pdf', 'PDF');
        $reply = QuickReply::factory()->create([
            'attachment_path' => 'quick-replies/brochure.pdf',
            'attachment_name' => 'Brochure.pdf',
            'attachment_mime' => 'application/pdf',
        ]);

        $this->actingAs($agent)->postJson(route('conversations.messages.store', $conversation), [
            'body' => 'Our brochure',
            'quick_reply_id' => $reply->id,
        ])->assertCreated();

        $message = Message::where('direction', 'outbound')->sole();
        $this->assertSame(MessageType::Document, $message->type);
        $this->assertNotSame('quick-replies/brochure.pdf', $message->media['path']);
        Storage::disk('local')->assertExists($message->media['path']);
    }

    public function test_agent_can_send_an_approved_template_after_the_window_closes()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.TPL']]])]);

        $agent = User::factory()->admin()->create();
        $channel = Channel::factory()->create();
        $conversation = Conversation::factory()->for($channel)->windowClosed()->create();
        $conversation->contact->update(['name' => 'Kerry Tan']);
        $template = WhatsappTemplate::factory()->for($channel)->create(['name' => 'tour_update', 'language' => 'en']);

        $this->actingAs($agent)->postJson(route('conversations.template.store', $conversation), [
            'template_id' => $template->id,
            'values' => ['body.1' => '{first_name}', 'body.2' => 'Hokkaido'],
        ])->assertCreated();

        $message = Message::where('direction', 'outbound')->sole();
        $this->assertSame(MessageType::Template, $message->type);
        $this->assertStringContainsString('Hi Kerry, our Hokkaido tour has new dates!', $message->body);

        Http::assertSent(fn (Request $request) => $request['type'] === 'template'
            && $request['template']['name'] === 'tour_update'
            && $request['template']['language']['code'] === 'en'
            && $request['template']['components'][0]['parameters'][0]['text'] === 'Kerry'
            && $request['template']['components'][0]['parameters'][1]['text'] === 'Hokkaido');
    }

    public function test_template_variables_are_required()
    {
        $agent = User::factory()->admin()->create();
        $channel = Channel::factory()->create();
        $conversation = Conversation::factory()->for($channel)->create();
        $template = WhatsappTemplate::factory()->for($channel)->create();

        $this->actingAs($agent)->postJson(route('conversations.template.store', $conversation), [
            'template_id' => $template->id,
            'values' => ['body.1' => 'Kerry'],
        ])->assertUnprocessable()->assertJsonValidationErrors('values');
    }

    public function test_demo_mode_does_not_call_meta()
    {
        config(['services.meta.fake' => true]);
        Http::fake();

        $agent = User::factory()->admin()->create();
        $conversation = Conversation::factory()->create();

        $this->actingAs($agent)->postJson(route('conversations.messages.store', $conversation), ['body' => 'Hi'])->assertCreated();

        Http::assertNothingSent();
        $this->assertSame(MessageStatus::Sent, Message::where('direction', 'outbound')->sole()->status);
    }
}
