<?php

namespace Tests\Feature;

use App\Models\QuickReply;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QuickReplyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_shared_reply_with_an_attachment()
    {
        Storage::fake('local');
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('quick-replies.store'), [
            'shortcut' => '/Price',
            'title' => 'Japan price',
            'body' => 'Hi {first_name}, it is RM4,999.',
            'is_shared' => true,
            'attachment' => UploadedFile::fake()->create('brochure.pdf', 50, 'application/pdf'),
        ])->assertSessionHasNoErrors();

        $reply = QuickReply::sole();
        $this->assertSame('price', $reply->shortcut);
        $this->assertTrue($reply->is_shared);
        Storage::disk('local')->assertExists($reply->attachment_path);

        $this->actingAs(User::factory()->create())->get(route('quick-replies.attachment', $reply))->assertOk();
    }

    public function test_agents_can_only_create_personal_replies()
    {
        $agent = User::factory()->create();

        $this->actingAs($agent)->post(route('quick-replies.store'), [
            'shortcut' => 'hi',
            'title' => 'Greeting',
            'body' => 'Hello!',
            'is_shared' => true,
        ]);

        $this->assertFalse(QuickReply::sole()->is_shared);
    }

    public function test_agents_see_shared_replies_and_their_own_only()
    {
        $agent = User::factory()->create();
        QuickReply::factory()->create(['is_shared' => true]);
        QuickReply::factory()->create(['is_shared' => false, 'user_id' => $agent->id]);
        QuickReply::factory()->create(['is_shared' => false]);

        $this->actingAs($agent)->get(route('quick-replies.index'))
            ->assertInertia(fn ($page) => $page->component('quick-replies/Index')->has('quickReplies', 2));
    }

    public function test_agents_cannot_edit_shared_replies()
    {
        $reply = QuickReply::factory()->create(['is_shared' => true]);

        $this->actingAs(User::factory()->create())
            ->post(route('quick-replies.update', $reply), ['shortcut' => 'x', 'title' => 'x', 'body' => 'x'])
            ->assertForbidden();
    }

    public function test_shortcuts_cannot_contain_spaces()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('quick-replies.store'), ['shortcut' => 'two words', 'title' => 'x', 'body' => 'x'])
            ->assertSessionHasErrors('shortcut');
    }
}
