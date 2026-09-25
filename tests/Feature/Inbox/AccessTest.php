<?php

namespace Tests\Feature\Inbox;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Setting;
use App\Models\User;
use App\Services\Inbox\MediaStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_agents_see_their_own_and_unassigned_chats_by_default()
    {
        $agent = User::factory()->create();
        $other = User::factory()->create();

        $mine = Conversation::factory()->create(['assigned_user_id' => $agent->id]);
        $unassigned = Conversation::factory()->create();
        $theirs = Conversation::factory()->create(['assigned_user_id' => $other->id]);

        $this->actingAs($agent)->get(route('inbox.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('inbox/Index')
                ->has('conversations', 2)
                ->where('counts.all', 2)
                ->where('counts.mine', 1)
                ->where('counts.unassigned', 1));

        $this->actingAs($agent)->get(route('inbox.show', $mine))->assertOk();
        $this->actingAs($agent)->get(route('inbox.show', $unassigned))->assertOk();
        $this->actingAs($agent)->get(route('inbox.show', $theirs))->assertForbidden();
        $this->actingAs($agent)->postJson(route('conversations.messages.store', $theirs), ['body' => 'x'])->assertForbidden();
    }

    public function test_visibility_can_be_set_to_own_chats_only_or_all_chats()
    {
        $agent = User::factory()->create();
        Conversation::factory()->create(['assigned_user_id' => $agent->id]);
        Conversation::factory()->create();
        Conversation::factory()->create(['assigned_user_id' => User::factory()->create()->id]);

        Setting::set('inbox.visibility', 'own');
        $this->actingAs($agent)->get(route('inbox.index'))->assertInertia(fn ($page) => $page->has('conversations', 1));

        Setting::set('inbox.visibility', 'all');
        $this->actingAs($agent)->get(route('inbox.index'))->assertInertia(fn ($page) => $page->has('conversations', 3));
    }

    public function test_admins_see_everything()
    {
        Conversation::factory()->count(2)->create(['assigned_user_id' => User::factory()->create()->id]);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('inbox.index'))
            ->assertInertia(fn ($page) => $page->has('conversations', 2));
    }

    public function test_inbox_filters()
    {
        $admin = User::factory()->admin()->create();
        $kerry = Contact::factory()->create(['name' => 'Kerry Tan', 'phone' => '60123456789']);
        Conversation::factory()->create(['contact_id' => $kerry->id]);
        Conversation::factory()->create(['assigned_user_id' => $admin->id]);
        Conversation::factory()->create(['status' => 'closed']);

        $this->actingAs($admin)->get(route('inbox.index', ['q' => 'kerry']))->assertInertia(fn ($page) => $page->has('conversations', 1));
        $this->actingAs($admin)->get(route('inbox.index', ['q' => '012-3456789']))->assertInertia(fn ($page) => $page->has('conversations', 1));
        $this->actingAs($admin)->get(route('inbox.index', ['folder' => 'mine']))->assertInertia(fn ($page) => $page->has('conversations', 1));
        $this->actingAs($admin)->get(route('inbox.index', ['status' => 'closed']))->assertInertia(fn ($page) => $page->has('conversations', 1));
    }

    public function test_opening_a_chat_marks_it_as_read()
    {
        $admin = User::factory()->admin()->create();
        $conversation = Conversation::factory()->create(['unread_count' => 3]);
        Message::factory()->for($conversation)->create();

        $this->actingAs($admin)->get(route('inbox.show', $conversation))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('selected.messages', 1)->where('selected.can_reply', true));

        $this->assertSame(0, $conversation->fresh()->unread_count);
    }

    public function test_chat_media_is_only_served_to_agents_who_can_see_the_chat()
    {
        Storage::fake('local');
        $stored = MediaStore::put('IMG', 'image/jpeg', 'photo.jpg');
        $conversation = Conversation::factory()->create(['assigned_user_id' => User::factory()->create()->id]);
        $message = Message::factory()->for($conversation)->create(['type' => 'image', 'media' => $stored]);

        $this->get(route('media.show', $message))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('media.show', $message))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(route('media.show', $message))->assertOk();
    }

    public function test_agents_can_assign_and_close_chats()
    {
        $agent = User::factory()->create();
        $colleague = User::factory()->create();
        $conversation = Conversation::factory()->create(['assigned_user_id' => $agent->id]);

        $this->actingAs($agent)->patch(route('conversations.assign', $conversation), ['user_id' => $colleague->id])->assertSessionHasNoErrors();
        $this->assertSame($colleague->id, $conversation->fresh()->assigned_user_id);

        // The chat is no longer visible to the first agent.
        $this->actingAs($agent)->patch(route('conversations.status', $conversation), ['status' => 'closed'])->assertForbidden();

        $this->actingAs($colleague)->patch(route('conversations.status', $conversation), ['status' => 'closed'])->assertSessionHasNoErrors();
        $this->assertSame('closed', $conversation->fresh()->status->value);
    }

    public function test_cannot_assign_to_a_deactivated_agent()
    {
        $admin = User::factory()->admin()->create();
        $conversation = Conversation::factory()->create();

        $this->actingAs($admin)
            ->patch(route('conversations.assign', $conversation), ['user_id' => User::factory()->inactive()->create()->id])
            ->assertSessionHasErrors('user_id');
    }
}
