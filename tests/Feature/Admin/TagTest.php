<?php

namespace Tests\Feature\Admin;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_manage_tags_with_keywords()
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post(route('admin.tags.store'), ['name' => 'Japan', 'color' => 'red', 'keywords' => 'japan, tokyo , ,osaka'])
            ->assertSessionHasNoErrors();

        $tag = Tag::sole();
        $this->assertSame(['japan', 'tokyo', 'osaka'], $tag->keywords);

        $this->actingAs($admin)->patch(route('admin.tags.update', $tag), ['name' => 'Japan Tour', 'color' => 'blue', 'keywords' => ''])
            ->assertSessionHasNoErrors();
        $this->assertSame('Japan Tour', $tag->fresh()->name);
        $this->assertSame([], $tag->fresh()->keywords);

        $this->actingAs($admin)->delete(route('admin.tags.destroy', $tag));
        $this->assertSame(0, Tag::count());
    }

    public function test_tag_names_are_unique_and_colors_are_validated()
    {
        $admin = User::factory()->admin()->create();
        Tag::factory()->create(['name' => 'Japan']);

        $this->actingAs($admin)->post(route('admin.tags.store'), ['name' => 'Japan', 'color' => 'hotpink'])
            ->assertSessionHasErrors(['name', 'color']);
    }

    public function test_agents_cannot_manage_tags()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('admin.tags.store'), ['name' => 'X', 'color' => 'red'])
            ->assertForbidden();
    }
}
