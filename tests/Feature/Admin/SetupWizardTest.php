<?php

namespace Tests\Feature\Admin;

use App\Enums\ChannelType;
use App\Models\Channel;
use App\Models\Setting;
use App\Models\User;
use App\Support\MetaSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SetupWizardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.meta.fake' => false, 'services.meta.app_id' => null, 'services.meta.app_secret' => null, 'services.meta.webhook_verify_token' => null]);
        Http::preventStrayRequests();
        $this->admin = User::factory()->admin()->create();
    }

    public function test_the_wizard_lists_all_steps_and_creates_a_verify_token()
    {
        $this->actingAs($this->admin)->get(route('admin.setup.show'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/Setup')
                ->has('steps', 18)
                ->where('steps.0.key', 'facebook_account')
                ->where('steps.0.status', 'todo'));

        $this->assertSame(32, strlen(MetaSettings::get('verify_token')));
    }

    public function test_agents_cannot_open_the_wizard()
    {
        $this->actingAs(User::factory()->create())->get(route('admin.setup.show'))->assertForbidden();
    }

    public function test_manual_steps_are_marked_done_and_can_be_undone()
    {
        $this->actingAs($this->admin)->post(route('admin.setup.complete', 'facebook_page'))->assertSessionHasNoErrors();
        $this->assertSame('done', Setting::get('onboarding.progress')['facebook_page']['status']);

        $this->actingAs($this->admin)->post(route('admin.setup.complete', 'business_verification'), ['waiting' => true]);
        $this->assertSame('waiting', Setting::get('onboarding.progress')['business_verification']['status']);

        $this->actingAs($this->admin)->delete(route('admin.setup.undo', 'facebook_page'));
        $this->assertArrayNotHasKey('facebook_page', Setting::get('onboarding.progress'));
    }

    public function test_app_credentials_are_checked_with_meta_and_saved_encrypted()
    {
        Http::fake(['graph.facebook.com/*/123456'.'*' => Http::response(['id' => '123456', 'name' => 'HolidayGoGoGo CRM'])]);

        $this->actingAs($this->admin)
            ->post(route('admin.setup.complete', 'app_credentials'), ['app_id' => '123456', 'app_secret' => 'shh'])
            ->assertSessionHasNoErrors();

        $this->assertSame('123456', MetaSettings::get('app_id'));
        $this->assertSame('shh', MetaSettings::get('app_secret'));
        $this->assertNotSame('shh', Setting::get('meta.app_secret'));
        $this->assertSame('shh', config('services.meta.app_secret'));
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer 123456|shh'));
    }

    public function test_wrong_app_secret_shows_metas_reason()
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Invalid OAuth access token', 'code' => 190]], 400)]);

        $this->actingAs($this->admin)
            ->post(route('admin.setup.complete', 'app_credentials'), ['app_id' => '123456', 'app_secret' => 'wrong'])
            ->assertSessionHasErrors('check');

        $this->assertNull(MetaSettings::get('app_secret'));
        $this->assertArrayNotHasKey('app_credentials', (array) Setting::get('onboarding.progress', []));
    }

    public function test_token_without_whatsapp_permissions_is_rejected()
    {
        Http::fake([
            'graph.facebook.com/v23.0/me?*' => Http::response(['id' => '1', 'name' => 'CRM']),
            'graph.facebook.com/v23.0/me/permissions*' => Http::response(['data' => [['permission' => 'pages_messaging', 'status' => 'granted']]]),
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.setup.complete', 'system_token'), ['system_token' => 'EAAG'])
            ->assertSessionHasErrors('check');
    }

    public function test_whatsapp_number_step_creates_the_channel()
    {
        MetaSettings::set('system_token', 'EAAG-system');
        Http::fake([
            'graph.facebook.com/v23.0/1111?*' => Http::response(['display_phone_number' => '+60 12-888 8888', 'verified_name' => 'HolidayGoGoGo']),
            'graph.facebook.com/v23.0/2222/subscribed_apps' => Http::response(['success' => true]),
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.setup.complete', 'whatsapp_ids'), ['phone_number_id' => '1111', 'waba_id' => '2222'])
            ->assertSessionHasNoErrors();

        $channel = Channel::sole();
        $this->assertSame(ChannelType::WhatsApp, $channel->type);
        $this->assertSame('EAAG-system', $channel->access_token);
        $this->assertSame('+60 12-888 8888', $channel->display_phone);
    }

    public function test_messenger_step_stores_the_page_token()
    {
        MetaSettings::set('system_token', 'EAAG-system');
        Http::fake([
            'graph.facebook.com/v23.0/5555?*' => Http::response(['name' => 'HolidayGoGoGo', 'access_token' => 'PAGE-TOKEN']),
            'graph.facebook.com/v23.0/5555/subscribed_apps' => Http::response(['success' => true]),
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.setup.complete', 'messenger_page'), ['page_id' => '5555'])
            ->assertSessionHasNoErrors();

        $this->assertSame('PAGE-TOKEN', Channel::where('type', 'messenger')->sole()->access_token);
    }

    public function test_webhook_step_passes_once_meta_has_verified_the_url()
    {
        MetaSettings::set('verify_token', 'tok');

        $this->actingAs($this->admin)->post(route('admin.setup.complete', 'whatsapp_webhook'))->assertSessionHasErrors('check');

        $this->get('/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=tok&hub.challenge=42')->assertOk();

        $this->actingAs($this->admin)->post(route('admin.setup.complete', 'whatsapp_webhook'))->assertSessionHasNoErrors();
    }

    public function test_privacy_page_is_public()
    {
        $this->get(route('privacy'))->assertOk()->assertSee('Privacy Policy');
    }
}
