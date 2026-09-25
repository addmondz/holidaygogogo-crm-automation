<?php

namespace Tests\Feature\Admin;

use App\Models\Channel;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\WhatsappTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChannelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.meta.fake' => false]);
        Http::preventStrayRequests();
    }

    public function test_admin_can_connect_a_whatsapp_number_and_the_token_is_encrypted()
    {
        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.channels.store'), [
                'type' => 'whatsapp',
                'name' => 'Main number',
                'external_id' => '106540352242922',
                'business_account_id' => '102290129340398',
                'access_token' => 'EAAG-secret',
            ])
            ->assertSessionHasNoErrors();

        $channel = Channel::sole();
        $this->assertSame('EAAG-secret', $channel->access_token);
        $this->assertNotSame('EAAG-secret', $channel->getRawOriginal('access_token'));
    }

    public function test_leaving_the_token_empty_keeps_the_saved_one()
    {
        $channel = Channel::factory()->create(['access_token' => 'old-token']);

        $this->actingAs(User::factory()->admin()->create())
            ->patch(route('admin.channels.update', $channel), [
                'name' => 'Renamed',
                'external_id' => $channel->external_id,
                'access_token' => '',
                'is_active' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('old-token', $channel->fresh()->access_token);
        $this->assertSame('Renamed', $channel->fresh()->name);
    }

    public function test_channels_with_chats_cannot_be_deleted()
    {
        $channel = Channel::factory()->create();
        Conversation::factory()->for($channel)->create();

        $this->actingAs(User::factory()->admin()->create())
            ->delete(route('admin.channels.destroy', $channel))
            ->assertSessionHasErrors('channel');

        $this->assertNotNull($channel->fresh());
    }

    public function test_test_connection_checks_the_number_and_subscribes_webhooks()
    {
        Http::fake([
            'graph.facebook.com/v23.0/1111?*' => Http::response(['display_phone_number' => '+60 12-888 8888', 'verified_name' => 'HolidayGoGoGo', 'quality_rating' => 'GREEN']),
            'graph.facebook.com/v23.0/2222/subscribed_apps' => Http::response(['success' => true]),
        ]);

        $channel = Channel::factory()->create(['external_id' => '1111', 'business_account_id' => '2222']);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.channels.test', $channel))
            ->assertRedirect();

        $this->assertSame('+60 12-888 8888', $channel->fresh()->display_phone);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/2222/subscribed_apps'));
    }

    public function test_templates_are_synced_from_meta()
    {
        $channel = Channel::factory()->create(['business_account_id' => '2222']);
        $old = WhatsappTemplate::factory()->for($channel)->create(['name' => 'removed_template']);

        Http::fake([
            'graph.facebook.com/v23.0/2222/message_templates*' => Http::response([
                'data' => [
                    ['id' => '1', 'name' => 'tour_promo', 'language' => 'en', 'status' => 'APPROVED', 'category' => 'MARKETING', 'components' => [['type' => 'BODY', 'text' => 'Hi {{1}}']]],
                    ['id' => '2', 'name' => 'tour_promo', 'language' => 'ms', 'status' => 'PENDING', 'category' => 'MARKETING', 'components' => []],
                ],
                'paging' => [],
            ]),
        ]);

        $this->actingAs(User::factory()->admin()->create())->post(route('admin.channels.sync-templates', $channel));

        $this->assertSame(3, WhatsappTemplate::count());
        $this->assertSame('DELETED', $old->fresh()->status);
        $this->assertTrue(WhatsappTemplate::where('language', 'en')->where('name', 'tour_promo')->sole()->isApproved());
    }

    public function test_simulator_only_works_in_demo_mode()
    {
        $admin = User::factory()->admin()->create();
        $channel = Channel::factory()->create();
        $data = ['channel_id' => $channel->id, 'name' => 'Test Lead', 'from' => '0123456789', 'text' => 'Hello'];

        $this->actingAs($admin)->post(route('admin.simulate'), $data)->assertNotFound();

        config(['services.meta.fake' => true]);
        $this->actingAs($admin)->post(route('admin.simulate'), $data)->assertSessionHasNoErrors();

        $this->assertSame('Hello', Message::sole()->body);
        $this->assertSame('Test Lead', Conversation::sole()->contact->name);
        $this->assertSame('60123456789', Conversation::sole()->external_id);
    }
}
