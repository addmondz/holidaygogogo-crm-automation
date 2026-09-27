<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_agents_cannot_open_admin_pages()
    {
        $agent = User::factory()->create();

        $this->actingAs($agent)->get(route('admin.agents.index'))->assertForbidden();
        $this->actingAs($agent)->get(route('admin.settings.edit'))->assertForbidden();
    }

    public function test_admin_can_list_agents()
    {
        $admin = User::factory()->admin()->create();
        User::factory()->count(2)->create();

        $this->actingAs($admin)
            ->get(route('admin.agents.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('admin/Agents')->has('agents', 3));
    }

    public function test_admin_can_add_an_agent_who_can_then_log_in()
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.agents.store'), [
                'name' => 'Aisyah',
                'email' => 'aisyah@example.com',
                'role' => 'agent',
                'password' => 'secret-password',
            ])
            ->assertSessionHasNoErrors();

        $agent = User::where('email', 'aisyah@example.com')->firstOrFail();
        $this->assertSame(UserRole::Agent, $agent->role);

        auth()->logout();

        $this->post(route('login.store'), ['email' => 'aisyah@example.com', 'password' => 'secret-password']);
        $this->assertAuthenticatedAs($agent);
    }

    public function test_deactivated_agents_cannot_log_in()
    {
        $agent = User::factory()->inactive()->create();

        $this->post(route('login.store'), ['email' => $agent->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_agents_deactivated_while_logged_in_are_signed_out()
    {
        $agent = User::factory()->create();
        $this->actingAs($agent)->get(route('dashboard'))->assertOk();

        $agent->update(['is_active' => false]);

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_admin_can_update_an_agent_without_changing_password()
    {
        $admin = User::factory()->admin()->create();
        $agent = User::factory()->create();
        $oldHash = $agent->password;

        $this->actingAs($admin)
            ->patch(route('admin.agents.update', $agent), [
                'name' => 'New Name',
                'email' => $agent->email,
                'role' => 'admin',
                'is_active' => true,
                'is_available' => false,
                'password' => '',
            ])
            ->assertSessionHasNoErrors();

        $agent->refresh();
        $this->assertSame('New Name', $agent->name);
        $this->assertTrue($agent->isAdmin());
        $this->assertFalse($agent->is_available);
        $this->assertSame($oldHash, $agent->password);
    }

    public function test_the_last_admin_cannot_be_demoted_or_deactivated()
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->patch(route('admin.agents.update', $admin), [
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => 'agent',
                'is_active' => true,
                'is_available' => true,
            ])
            ->assertSessionHasErrors('role');

        $this->assertTrue($admin->fresh()->isAdmin());
    }

    public function test_deleting_an_agent_unassigns_their_chats()
    {
        $admin = User::factory()->admin()->create();
        $agent = User::factory()->create();
        $conversation = Conversation::factory()->create(['assigned_user_id' => $agent->id]);

        $this->actingAs($admin)->delete(route('admin.agents.destroy', $agent))->assertSessionHasNoErrors();

        $this->assertNull($agent->fresh());
        $this->assertNull($conversation->fresh()->assigned_user_id);
    }

    public function test_admin_cannot_delete_themselves()
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->delete(route('admin.agents.destroy', $admin))->assertSessionHasErrors('agent');

        $this->assertNotNull($admin->fresh());
    }

    public function test_agents_can_toggle_their_availability()
    {
        $agent = User::factory()->create(['is_available' => true]);

        $this->actingAs($agent)->patch(route('availability.update'), ['is_available' => false]);

        $this->assertFalse($agent->fresh()->is_available);
    }
}
