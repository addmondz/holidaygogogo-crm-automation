<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HorizonAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_can_open_the_queue_monitor()
    {
        $this->app['env'] = 'local';

        $this->get('/horizon/api/stats')->assertForbidden();
        $this->actingAs(User::factory()->create())->get('/horizon/api/stats')->assertForbidden();
    }
}
