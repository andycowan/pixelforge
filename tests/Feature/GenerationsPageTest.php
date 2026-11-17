<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GenerationsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('generations.index'))->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_generations_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('generations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Generations'));
    }
}
