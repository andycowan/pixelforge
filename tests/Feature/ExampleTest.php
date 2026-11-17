<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_redirects_guests_to_the_dashboard()
    {
        $this->get(route('home'))->assertRedirect(route('dashboard'));

        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_home_redirects_authenticated_users_to_the_dashboard()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('home'))
            ->assertRedirect(route('dashboard'));

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();
    }
}
