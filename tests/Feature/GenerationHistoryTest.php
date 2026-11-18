<?php

namespace Tests\Feature;

use App\Actions\Generations\ApplyProviderGeneration;
use App\Enums\GenerationSubmissionStatus;
use App\Models\Generation;
use App\Models\GenerationCharge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\GenerationEvidence;
use Tests\TestCase;

final class GenerationHistoryTest extends TestCase
{
    use GenerationEvidence, RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('generations.index'))->assertRedirect(route('login'));
    }

    public function test_unverified_users_are_forbidden(): void
    {
        // The verified middleware redirects HTML requests but forbids JSON
        // requests, which is what an Inertia navigation sends.
        $this->actingAs(User::factory()->unverified()->create())
            ->getJson(route('generations.index'))
            ->assertForbidden();
    }

    public function test_users_only_see_their_own_generations(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $mine = Generation::factory()->create(['user_id' => $user->id, 'prompt' => 'Mine only']);
        Generation::factory()->create(['user_id' => $other->id, 'prompt' => 'Someone else']);

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Generations')
                ->has('generations.data', 1)
                ->where('generations.data.0.id', $mine->id)
                ->where('generations.data.0.prompt', 'Mine only')
                ->where('generations.total', 1)
            );
    }

    public function test_search_matches_the_users_own_prompts_case_insensitively(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Generation::factory()->for($user)->create(['prompt' => 'A Lighthouse at dusk']);
        Generation::factory()->for($user)->create(['prompt' => 'A red fox']);
        Generation::factory()->for($other)->create(['prompt' => 'Another lighthouse']);

        $this->actingAs($user)->get(route('generations.index', ['search' => 'lighthouse']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('search', 'lighthouse')
                ->where('generations.total', 1)
                ->where('generations.data.0.prompt', 'A Lighthouse at dusk'));
    }

    public function test_search_treats_wildcards_literally_and_blank_search_shows_everything(): void
    {
        $user = User::factory()->create();
        foreach (['100% cotton shirt', 'snake_case sign', 'A back\\slash', 'Plain prompt'] as $prompt) {
            Generation::factory()->for($user)->create(['prompt' => $prompt]);
        }
        $total = fn (?string $search): int => $this->actingAs($user)
            ->get(route('generations.index', $search === null ? [] : ['search' => $search]))
            ->viewData('page')['props']['generations']['total'];

        $this->assertSame(1, $total('%'));
        $this->assertSame(1, $total('_'));
        $this->assertSame(1, $total('\\'));
        $this->assertSame(1, $total('100%'));
        $this->assertSame(0, $total('100%x'));
        $this->assertSame(4, $total(''));
        $this->assertSame(4, $total('   '));
        $this->assertSame(4, $total(null));
    }

    public function test_search_is_kept_in_pagination_links_and_bounded(): void
    {
        $user = User::factory()->create();
        Generation::factory()->count(21)->for($user)->create(['prompt' => 'Harbour scene']);

        $next = $this->actingAs($user)->get(route('generations.index', ['search' => 'harbour']))
            ->viewData('page')['props']['generations']['next_page_url'];
        $this->assertStringContainsString('search=harbour', $next);

        $this->actingAs($user)->get(route('generations.index', ['search' => str_repeat('a', 201)]))
            ->assertSessionHasErrors('search');
    }

    public function test_the_projection_is_paginated_newest_first(): void
    {
        $user = User::factory()->create();

        $generations = Generation::factory()
            ->count(21)
            ->sequence(fn ($sequence) => ['prompt' => 'Prompt '.$sequence->index])
            ->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Generations')
                ->has('generations.data', 20)
                ->where('generations.current_page', 1)
                ->where('generations.last_page', 2)
                ->where('generations.per_page', 20)
                ->where('generations.total', 21)
                ->where('generations.data.0.id', $generations->last()->id)
            );

        $this->actingAs($user)
            ->get(route('generations.index', ['page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Generations')
                ->has('generations.data', 1)
                ->where('generations.current_page', 2)
                ->where('generations.data.0.id', $generations->first()->id)
            );
    }

    public function test_pending_actual_cost_is_unknown_not_zero(): void
    {
        $user = User::factory()->create();
        Generation::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Generations')
                ->where('generations.data.0.submission_status', 'pending')
                ->where('generations.data.0.provider_status', null)
                ->where('generations.data.0.actual_cost_usd', null)
                ->where('generations.data.0.submission_message', null)
                ->where('generations.data.0.estimated_cost_usd', '0.080000000')
                ->where('generations.data.0.maximum_cost_usd', '0.090000000')
            );
    }

    public function test_a_known_rejection_reports_zero_and_a_safe_message(): void
    {
        $user = User::factory()->create();
        $generation = Generation::factory()->create(['user_id' => $user->id]);

        DB::transaction(function () use ($generation): void {
            Generation::query()->lockForUpdate()->findOrFail($generation->id)->recordSubmissionProblem(
                ['code' => 'content_policy_violation', 'message' => 'Provider diagnostic is private.', 'retryable' => false],
                null,
                GenerationSubmissionStatus::Rejected,
            );
        });

        $response = $this->actingAs($user)->get(route('generations.index'))->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Generations')
            ->where('generations.data.0.submission_status', 'rejected')
            ->where('generations.data.0.actual_cost_usd', '0.000000000')
            ->where('generations.data.0.submission_message', 'The provider could not accept this prompt. You were not charged.')
        );

        $this->assertStringNotContainsString('Provider diagnostic is private.', $response->getContent());
    }

    public function test_a_provider_failure_reports_zero_without_a_private_error(): void
    {
        $user = User::factory()->create();
        $generation = Generation::factory()->create(['user_id' => $user->id]);
        $this->prepare($generation);

        app(ApplyProviderGeneration::class)->execute(
            $generation->id,
            $this->evidence($generation, 'failed'),
            $this->fingerprint(),
        );

        $response = $this->actingAs($user)->get(route('generations.index'))->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Generations')
            ->where('generations.data.0.provider_status', 'failed')
            ->where('generations.data.0.actual_cost_usd', '0.000000000')
        );

        $this->assertStringNotContainsString('Private provider diagnostic', $response->getContent());
        $this->assertDatabaseCount('generation_charges', 0);
    }

    public function test_a_successful_generation_reports_the_settled_charge_exactly(): void
    {
        $user = User::factory()->create();
        $generation = Generation::factory()->create(['user_id' => $user->id]);
        $this->prepare($generation);

        app(ApplyProviderGeneration::class)->execute(
            $generation->id,
            $this->evidence($generation),
            $this->fingerprint(),
        );

        $charge = GenerationCharge::query()->findOrFail($generation->id);

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Generations')
                ->where('generations.data.0.provider_status', 'succeeded')
                ->where('generations.data.0.actual_cost_usd', '0.080000000')
                ->where('generations.data.0.actual_cost_usd', $charge->customerCostUsd())
                ->where('generations.data.0.image_url', 'https://example.com/result.png?signature=first')
                ->where('generations.data.0.submission_message', null)
            );
    }

    public function test_the_page_never_exposes_internal_submission_or_provider_evidence(): void
    {
        $user = User::factory()->create();
        $generation = Generation::factory()->create(['user_id' => $user->id]);
        $this->prepare($generation);

        app(ApplyProviderGeneration::class)->execute(
            $generation->id,
            $this->evidence($generation),
            $this->fingerprint(),
        );

        $fresh = Generation::query()->findOrFail($generation->id);
        $response = $this->actingAs($user)->get(route('generations.index'))->assertOk();

        $response->assertInertia(fn (Assert $page) => $page
            ->component('Generations')
            ->missing('generations.data.0.provider_id')
            ->missing('generations.data.0.provider_key_fingerprint')
            ->missing('generations.data.0.submission_token')
            ->missing('generations.data.0.quote_snapshot')
            ->missing('generations.data.0.rates_snapshot')
            ->missing('generations.data.0.usage')
            ->missing('generations.data.0.submission_error')
            ->missing('generations.data.0.provider_error')
            ->missing('generations.data.0.revision')
        );

        $content = (string) $response->getContent();

        $this->assertStringNotContainsString($this->fingerprint(), $content);
        $this->assertStringNotContainsString((string) $fresh->provider_id, $content);
        $this->assertStringNotContainsString($fresh->submission_token, $content);
    }

    public function test_an_expired_result_url_is_returned_unchanged_rather_than_refreshed(): void
    {
        $user = User::factory()->create();
        $generation = Generation::factory()->create(['user_id' => $user->id]);
        $this->prepare($generation);

        app(ApplyProviderGeneration::class)->execute(
            $generation->id,
            $this->evidence($generation, changes: [
                'image_url' => 'https://example.com/result.png?signature=expired',
                'image_url_expires_at' => '2026-11-17T12:00:30Z',
            ]),
            $this->fingerprint(),
        );

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Generations')
                ->where('generations.data.0.image_url', 'https://example.com/result.png?signature=expired')
                ->where('generations.data.0.image_url_expires_at', '2026-11-17T12:00:30+00:00')
            );
    }

    public function test_an_uncertain_submission_is_reported_truthfully(): void
    {
        $user = User::factory()->create();
        $generation = Generation::factory()->create(['user_id' => $user->id]);

        DB::transaction(function () use ($generation): void {
            Generation::query()->lockForUpdate()->findOrFail($generation->id)->recordSubmissionProblem(
                ['code' => 'transport_unknown', 'message' => 'Transport failure.', 'retryable' => true],
                now()->addSeconds(30),
                GenerationSubmissionStatus::Uncertain,
            );
        });

        $this->actingAs($user)
            ->get(route('generations.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Generations')
                ->where('generations.data.0.submission_status', 'uncertain')
                ->where('generations.data.0.provider_status', null)
                ->where('generations.data.0.actual_cost_usd', null)
                ->where('generations.data.0.submission_message', 'Awaiting provider confirmation. Please do not submit the same request again.')
            );
    }

    private function prepare(Generation $generation): void
    {
        DB::transaction(function () use ($generation): void {
            Generation::query()->lockForUpdate()->findOrFail($generation->id)->prepareSubmission($this->fingerprint());
        });
    }
}
