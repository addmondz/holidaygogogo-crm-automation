<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_the_login_page()
    {
        $this->get(route('home'))->assertRedirect(route('login'));
    }

    public function test_logged_in_users_are_sent_to_the_app()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertRedirect();
    }

    public function test_public_registration_is_disabled()
    {
        $this->get('/register')->assertNotFound();
    }
}
