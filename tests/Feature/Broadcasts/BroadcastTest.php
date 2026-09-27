<?php

namespace Tests\Feature\Broadcasts;

use App\Enums\BroadcastStatus;
use App\Enums\MessageStatus;
use App\Enums\RecipientStatus;
use App\Jobs\SendBroadcastMessage;
use App\Jobs\StartBroadcast;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tag;
use App\Models\User;
use App\Models\WhatsappTemplate;
use App\Services\Inbox\Outbox;
use App\Services\Webhooks\WhatsAppWebhookHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\MetaPayloads;
use Tests\TestCase;

class BroadcastTest extends TestCase
{
    use MetaPayloads, RefreshDatabase;

    private User $admin;

    private Channel $channel;

    private WhatsappTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.meta.fake' => false]);
        Http::preventStrayRequests();

        $this->admin = User::factory()->admin()->create();
        $this->channel = Channel::factory()->create();
        $this->template = WhatsappTemplate::factory()->for($this->channel)->create(['name' => 'tour_promo']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Japan Winter Promo',
            'channel_id' => $this->channel->id,
            'whatsapp_template_id' => $this->template->id,
            'template_params' => ['body.1' => '{first_name|there}', 'body.2' => 'Hokkaido'],
            'audience' => ['tag_ids' => [], 'exclude_tag_ids' => [], 'statuses' => []],
            'intent' => 'send',
            ...$overrides,
        ];
    }

    public function test_blast_goes_to_tagged_contacts_and_skips_opted_out_and_excluded()
    {
        $japan = Tag::factory()->create();
        $booked = Tag::factory()->create();

        $kerry = Contact::factory()->create(['name' => 'Kerry Tan']);
        $kerry->tags()->attach($japan);
        $noName = Contact::factory()->create(['name' => null]);
        $noName->tags()->attach($japan);
        $optedOut = Contact::factory()->optedOut()->create();
        $optedOut->tags()->attach($japan);
        $alreadyBooked = Contact::factory()->create();
        $alreadyBooked->tags()->attach([$japan->id, $booked->id]);
        Contact::factory()->create(); // not tagged

        Http::fake(['graph.facebook.com/*/messages' => Http::sequence()
            ->push(['messages' => [['id' => 'wamid.B1']]])
            ->push(['messages' => [['id' => 'wamid.B2']]]),
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.broadcasts.store'), $this->payload([
                'audience' => ['tag_ids' => [$japan->id], 'exclude_tag_ids' => [$booked->id], 'statuses' => []],
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $broadcast = Broadcast::sole();
        $this->assertSame(BroadcastStatus::Completed, $broadcast->status);
        $this->assertSame(2, $broadcast->total_recipients);
        $this->assertEqualsCanonicalizing([$kerry->id, $noName->id], $broadcast->recipients()->pluck('contact_id')->all());
        $this->assertSame(2, $broadcast->recipients()->where('status', RecipientStatus::Sent)->count());

        Http::assertSent(fn (Request $request) => $request['to'] === $kerry->phone
            && $request['template']['components'][0]['parameters'][0]['text'] === 'Kerry');
        Http::assertSent(fn (Request $request) => $request['to'] === $noName->phone
            && $request['template']['components'][0]['parameters'][0]['text'] === 'there');

        // Each blast message also appears in the lead's chat history.
        $message = Message::where('external_id', 'wamid.B1')->sole();
        $this->assertSame($broadcast->id, $message->meta['broadcast']['id']);
        $this->assertNull($message->user_id);
        $this->assertSame(2, Conversation::count());
    }

    public function test_receipts_and_replies_update_the_blast_report()
    {
        $contact = Contact::factory()->create(['phone' => '60123456789']);
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.REPORT']]])]);

        $this->actingAs($this->admin)->post(route('admin.broadcasts.store'), $this->payload());

        $handler = app(WhatsAppWebhookHandler::class);
        $handler->handle($this->whatsappStatusPayload($this->channel, 'wamid.REPORT', 'delivered'));
        $handler->handle($this->whatsappStatusPayload($this->channel, 'wamid.REPORT', 'read'));
        $handler->handle($this->whatsappTextPayload($this->channel, '60123456789', 'YES please send details'));

        $recipient = BroadcastRecipient::sole();
        $this->assertSame(RecipientStatus::Read, $recipient->status);
        $this->assertNotNull($recipient->replied_at);

        $this->actingAs($this->admin)->get(route('admin.broadcasts.show', Broadcast::sole()))
            ->assertInertia(fn ($page) => $page->component('broadcasts/Show')
                ->where('counts.total', 1)
                ->where('counts.read', 1)
                ->where('counts.replied', 1));

        // The reply lands in the same chat as the blast message.
        $this->assertSame(1, Conversation::where('contact_id', $contact->id)->count());
    }

    public function test_failed_sends_are_recorded_per_recipient()
    {
        Contact::factory()->create();
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid parameter', 'code' => 100]], 400)]);

        $this->actingAs($this->admin)->post(route('admin.broadcasts.store'), $this->payload());

        $recipient = BroadcastRecipient::sole();
        $this->assertSame(RecipientStatus::Failed, $recipient->status);
        $this->assertStringContainsString('Invalid parameter', $recipient->error);
        $this->assertSame(BroadcastStatus::Completed, Broadcast::sole()->status);
        $this->assertSame(MessageStatus::Failed, Message::sole()->status);
    }

    public function test_blast_cannot_be_sent_with_missing_variables_or_empty_audience()
    {
        $this->actingAs($this->admin)
            ->post(route('admin.broadcasts.store'), $this->payload(['template_params' => ['body.1' => 'Hi']]))
            ->assertSessionHasErrors(['template_params', 'audience']);

        $this->assertSame(0, Broadcast::count());
    }

    public function test_drafts_can_be_saved_incomplete()
    {
        $this->actingAs($this->admin)
            ->post(route('admin.broadcasts.store'), $this->payload(['intent' => 'draft', 'whatsapp_template_id' => null]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.broadcasts.edit', Broadcast::sole()));
    }

    public function test_scheduled_blasts_start_at_the_right_time()
    {
        Queue::fake();
        Contact::factory()->create();

        $this->actingAs($this->admin)
            ->post(route('admin.broadcasts.store'), $this->payload(['scheduled_at' => now()->addHour()->toIso8601String()]));

        $broadcast = Broadcast::sole();
        $this->assertSame(BroadcastStatus::Scheduled, $broadcast->status);

        $this->artisan('broadcasts:dispatch-due')->assertSuccessful();
        Queue::assertNothingPushed();

        $this->travel(61)->minutes();
        $this->artisan('broadcasts:dispatch-due')->assertSuccessful();

        Queue::assertPushed(StartBroadcast::class, 1);
        $this->assertSame(BroadcastStatus::Sending, $broadcast->fresh()->status);
    }

    public function test_cancelled_blasts_skip_remaining_recipients()
    {
        Queue::fake();
        Contact::factory()->count(3)->create();

        $this->actingAs($this->admin)->post(route('admin.broadcasts.store'), $this->payload());
        $broadcast = Broadcast::sole();

        (new StartBroadcast($broadcast))->handle();
        $this->assertSame(3, $broadcast->recipients()->count());

        $this->actingAs($this->admin)->post(route('admin.broadcasts.cancel', $broadcast));
        $this->assertSame(BroadcastStatus::Cancelled, $broadcast->fresh()->status);

        foreach ($broadcast->recipients as $recipient) {
            (new SendBroadcastMessage($recipient->id, $this->channel->id))->handle(app(Outbox::class));
        }

        $this->assertSame(3, $broadcast->recipients()->where('status', RecipientStatus::Skipped)->count());
        Http::assertNothingSent();
    }

    public function test_messenger_blasts_only_reach_people_inside_the_24_hour_window()
    {
        $page = Channel::factory()->messenger()->create();
        $recent = Conversation::factory()->for($page)->create(['last_inbound_at' => now()->subHours(3)]);
        Conversation::factory()->for($page)->create(['last_inbound_at' => now()->subDays(3)]);

        Http::fake(['*' => Http::response(['message_id' => 'm_blast'])]);

        $this->actingAs($this->admin)
            ->post(route('admin.broadcasts.store'), $this->payload([
                'channel_id' => $page->id,
                'whatsapp_template_id' => null,
                'body' => 'Hi {first_name|there}! Flash sale today only 🎉',
            ]))
            ->assertSessionHasNoErrors();

        $recipient = BroadcastRecipient::sole();
        $this->assertSame($recent->contact_id, $recipient->contact_id);
        $this->assertSame(RecipientStatus::Sent, $recipient->status);

        Http::assertSent(fn (Request $request) => str_starts_with($request['message']['text'], 'Hi '.$recent->contact->first_name.'!'));
    }

    public function test_audience_preview_counts_matching_contacts()
    {
        Contact::factory()->count(3)->create();
        Contact::factory()->optedOut()->create();
        Contact::factory()->create(['phone' => null]);

        $this->actingAs($this->admin)
            ->getJson(route('admin.broadcasts.audience', ['channel_id' => $this->channel->id]))
            ->assertOk()
            ->assertJsonPath('count', 3);
    }

    public function test_send_a_test_message_to_one_number()
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.TEST']]])]);

        $this->actingAs($this->admin)->post(route('admin.broadcasts.test'), [
            'channel_id' => $this->channel->id,
            'whatsapp_template_id' => $this->template->id,
            'template_params' => ['body.1' => '{first_name}', 'body.2' => 'Bali'],
            'phone' => '012-999 8888',
        ])->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $request) => $request['to'] === '60129998888');
        $this->assertSame(0, Message::count());
        $this->assertSame(0, Contact::count());
    }

    public function test_agents_cannot_send_blasts()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('admin.broadcasts.store'), $this->payload())
            ->assertForbidden();
    }

    public function test_blasts_do_not_reset_unread_counts()
    {
        $conversation = Conversation::factory()->for($this->channel)->create(['unread_count' => 2]);
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.U']]])]);

        $this->actingAs($this->admin)->post(route('admin.broadcasts.store'), $this->payload());

        $this->assertSame(2, $conversation->fresh()->unread_count);
    }
}
