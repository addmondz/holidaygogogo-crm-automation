<?php

namespace Tests\Feature\Admin;

use App\Enums\InboxVisibility;
use App\Models\User;
use App\Support\CrmSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults()
    {
        $this->assertSame(InboxVisibility::OwnAndUnassigned, CrmSettings::visibility());
        $this->assertFalse(CrmSettings::autoAssign());
        $this->assertSame(['STOP', 'UNSUBSCRIBE'], CrmSettings::optOutKeywords());
    }

    public function test_admin_can_update_settings()
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patch(route('admin.settings.update'), [
                'visibility' => 'all',
                'auto_assign' => true,
                'opt_out_keywords' => 'stop, berhenti ,  ',
                'opt_in_keywords' => 'start',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(InboxVisibility::All, CrmSettings::visibility());
        $this->assertTrue(CrmSettings::autoAssign());
        $this->assertSame(['STOP', 'BERHENTI'], CrmSettings::optOutKeywords());
        $this->assertSame(['START'], CrmSettings::optInKeywords());
    }

    public function test_create_admin_command()
    {
        $this->artisan('crm:create-admin', [
            '--name' => 'Owner',
            '--email' => 'Owner@Example.com',
            '--password' => 'a-long-password',
        ])->assertSuccessful();

        $this->assertTrue(User::where('email', 'owner@example.com')->firstOrFail()->isAdmin());
    }
}
