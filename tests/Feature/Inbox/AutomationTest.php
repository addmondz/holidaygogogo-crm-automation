<?php

namespace Tests\Feature\Inbox;

use App\Enums\RecipientStatus;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use App\Services\Webhooks\WhatsAppWebhookHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MetaPayloads;
use Tests\TestCase;

class AutomationTest extends TestCase
{
    use MetaPayloads, RefreshDatabase;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = Channel::factory()->create();
    }

    private function receive(string $from, string $text): void
    {
        app(WhatsAppWebhookHandler::class)->handle($this->whatsappTextPayload($this->channel, $from, $text));
    }

    public function test_new_leads_are_shared_round_robin_between_available_agents()
    {
        Setting::set('assignment.auto_assign', true);

        $aisyah = User::factory()->create(['name' => 'Aisyah']);
        $ben = User::factory()->create(['name' => 'Ben']);
        User::factory()->create(['is_available' => false]);
        User::factory()->inactive()->create();

        $this->receive('60111111111', 'Hi');
        $this->receive('60122222222', 'Hi');
        $this->receive('60133333333', 'Hi');

        $assigned = Conversation::orderBy('id')->pluck('assigned_user_id')->all();
        $this->assertSame([$aisyah->id, $ben->id, $aisyah->id], $assigned);
    }

    public function test_auto_assign_is_off_by_default()
    {
        User::factory()->create();

        $this->receive('60111111111', 'Hi');

        $this->assertNull(Conversation::sole()->assigned_user_id);
    }

    public function test_follow_up_messages_stay_with_the_same_agent()
    {
        Setting::set('assignment.auto_assign', true);
        $first = User::factory()->create();
        User::factory()->create();

        $this->receive('60111111111', 'Hi');
        $this->receive('60111111111', 'Are you there?');

        $this->assertSame($first->id, Conversation::sole()->assigned_user_id);
    }

    public function test_keywords_tag_leads_automatically()
    {
        $japan = Tag::factory()->create(['name' => 'Japan', 'keywords' => ['japan', 'tokyo']]);
        Tag::factory()->create(['name' => 'Korea', 'keywords' => ['korea', 'seoul']]);

        $this->receive('60111111111', 'Looking for a Tokyo trip in spring');

        $this->assertSame([$japan->id], Contact::sole()->tags->modelKeys());
    }

    public function test_keywords_match_whole_words_only()
    {
        Tag::factory()->create(['name' => 'Bali', 'keywords' => ['bali']]);

        $this->receive('60111111111', 'Balistic!');

        $this->assertCount(0, Contact::sole()->tags);
    }

    public function test_customers_can_opt_out_and_back_in_to_blasts()
    {
        $this->receive('60111111111', 'Stop');
        $this->assertTrue(Contact::sole()->isOptedOut());

        $this->receive('60111111111', 'START');
        $this->assertFalse(Contact::sole()->fresh()->isOptedOut());
    }

    public function test_replies_to_a_blast_are_counted()
    {
        $contact = Contact::factory()->create(['phone' => '60111111111']);
        $recipient = BroadcastRecipient::create([
            'broadcast_id' => Broadcast::factory()->create(['channel_id' => $this->channel->id])->id,
            'contact_id' => $contact->id,
            'status' => RecipientStatus::Delivered,
            'sent_at' => now()->subHour(),
        ]);

        $this->receive('60111111111', 'Interested!');

        $this->assertNotNull($recipient->fresh()->replied_at);
    }

    public function test_a_closed_chat_reopens_when_the_customer_writes_again()
    {
        $this->receive('60111111111', 'Hi');
        Conversation::sole()->update(['status' => 'closed']);

        $this->receive('60111111111', 'One more question');

        $this->assertSame('open', Conversation::sole()->status->value);
    }
}
