<?php

namespace Tests\Feature\Contacts;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Note;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_agents_only_see_contacts_from_their_visible_chats()
    {
        $agent = User::factory()->create();
        Conversation::factory()->create(['assigned_user_id' => $agent->id]);
        Conversation::factory()->create(['assigned_user_id' => User::factory()->create()->id]);
        Contact::factory()->create(['source' => 'import']);

        $this->actingAs($agent)->get(route('contacts.index'))
            ->assertInertia(fn ($page) => $page->component('contacts/Index')->has('contacts.data', 1));

        $this->actingAs(User::factory()->admin()->create())->get(route('contacts.index'))
            ->assertInertia(fn ($page) => $page->has('contacts.data', 3));
    }

    public function test_admin_can_add_a_contact_with_a_local_phone_number()
    {
        $tag = Tag::factory()->create();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('contacts.store'), [
                'name' => 'Walk-in Customer',
                'phone' => '012-345 6789',
                'status' => 'interested',
                'tag_ids' => [$tag->id],
            ])
            ->assertSessionHasNoErrors();

        $contact = Contact::sole();
        $this->assertSame('60123456789', $contact->phone);
        $this->assertSame([$tag->id], $contact->tags->modelKeys());
    }

    public function test_agents_cannot_add_contacts()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('contacts.store'), ['name' => 'X', 'status' => 'new'])
            ->assertForbidden();
    }

    public function test_duplicate_phone_numbers_are_rejected()
    {
        Contact::factory()->create(['phone' => '60123456789']);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('contacts.store'), ['name' => 'Dup', 'phone' => '+60 12-345 6789', 'status' => 'new'])
            ->assertSessionHasErrors('phone');
    }

    public function test_agent_can_update_lead_status_and_tags_on_their_chat()
    {
        $agent = User::factory()->create();
        $conversation = Conversation::factory()->create(['assigned_user_id' => $agent->id]);
        $contact = $conversation->contact;
        $tags = Tag::factory()->count(2)->create();

        $this->actingAs($agent)
            ->patch(route('contacts.update', $contact), [
                'name' => 'Kerry Tan',
                'phone' => '+'.$contact->phone,
                'email' => 'kerry@example.com',
                'status' => 'booked',
            ])
            ->assertSessionHasNoErrors();

        $this->actingAs($agent)
            ->put(route('contacts.tags', $contact), ['tag_ids' => $tags->modelKeys()])
            ->assertSessionHasNoErrors();

        $contact->refresh();
        $this->assertSame('booked', $contact->status->value);
        $this->assertSame('kerry@example.com', $contact->email);
        $this->assertCount(2, $contact->tags);

        $this->actingAs($agent)->put(route('contacts.tags', $contact), ['tag_ids' => []]);
        $this->assertCount(0, $contact->fresh()->tags);
    }

    public function test_agents_cannot_edit_contacts_they_cannot_see()
    {
        $conversation = Conversation::factory()->create(['assigned_user_id' => User::factory()->create()->id]);

        $this->actingAs(User::factory()->create())
            ->patch(route('contacts.update', $conversation->contact), ['name' => 'X', 'status' => 'new'])
            ->assertForbidden();
    }

    public function test_notes_can_be_added_and_deleted_by_their_author()
    {
        $agent = User::factory()->create();
        $conversation = Conversation::factory()->create(['assigned_user_id' => $agent->id]);

        $this->actingAs($agent)
            ->post(route('contacts.notes.store', $conversation->contact), ['body' => 'Budget RM20k, 5 pax'])
            ->assertSessionHasNoErrors();

        $note = Note::sole();
        $this->assertSame($agent->id, $note->user_id);

        $this->actingAs(User::factory()->create())->delete(route('notes.destroy', $note))->assertForbidden();
        $this->actingAs($agent)->delete(route('notes.destroy', $note));
        $this->assertNull($note->fresh());
    }

    public function test_starting_a_whatsapp_chat_with_an_imported_contact()
    {
        $admin = User::factory()->admin()->create();
        $channel = Channel::factory()->create();
        $contact = Contact::factory()->create(['phone' => '60199999999']);

        $response = $this->actingAs($admin)->post(route('contacts.whatsapp', $contact), ['channel_id' => $channel->id]);

        $conversation = Conversation::sole();
        $response->assertRedirect(route('inbox.show', $conversation));
        $this->assertSame('60199999999', $conversation->external_id);
        $this->assertFalse($conversation->isWindowOpen());
    }
}
