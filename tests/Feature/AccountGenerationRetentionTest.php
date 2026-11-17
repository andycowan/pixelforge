<?php

namespace Tests\Feature;

use App\Exceptions\AccountHasGenerations;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountGenerationRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_account_with_generation_evidence_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $generation = Generation::factory()->for($user)->create();

        try {
            $user->deleteQuietly();
            $this->fail('Generation evidence must block end-user deletion.');
        } catch (AccountHasGenerations) {
            $this->assertDatabaseHas('users', ['id' => $user->id]);
            $this->assertSame($user->id, $generation->fresh()->user_id);
        }
    }

    public function test_http_deletion_is_rejected_without_logging_the_owner_out(): void
    {
        $user = User::factory()->create();
        Generation::factory()->for($user)->create();

        $this->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertSessionHasErrors('password')
            ->assertRedirect(route('profile.edit'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }
}
