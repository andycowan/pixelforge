<?php

namespace Tests\Unit;

use App\Enums\ProviderGenerationStatus;
use App\Enums\ProviderTransitionOutcome;
use App\StateMachines\ProviderGenerationStateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderGenerationStateMachineTest extends TestCase
{
    /** @return array<string, array{?ProviderGenerationStatus, ProviderGenerationStatus, ProviderTransitionOutcome}> */
    public static function transitionMatrix(): array
    {
        $queued = ProviderGenerationStatus::Queued;
        $processing = ProviderGenerationStatus::Processing;
        $succeeded = ProviderGenerationStatus::Succeeded;
        $failed = ProviderGenerationStatus::Failed;

        $advance = ProviderTransitionOutcome::Advance;
        $duplicate = ProviderTransitionOutcome::Duplicate;
        $stale = ProviderTransitionOutcome::Stale;
        $conflict = ProviderTransitionOutcome::Conflict;

        return [
            'no prior observation -> queued' => [null, $queued, $advance],
            'no prior observation -> processing' => [null, $processing, $advance],
            'no prior observation -> succeeded' => [null, $succeeded, $advance],
            'no prior observation -> failed' => [null, $failed, $advance],

            'queued -> queued' => [$queued, $queued, $duplicate],
            'queued -> processing' => [$queued, $processing, $advance],
            'queued -> succeeded' => [$queued, $succeeded, $advance],
            'queued -> failed' => [$queued, $failed, $advance],

            'processing -> queued' => [$processing, $queued, $stale],
            'processing -> processing' => [$processing, $processing, $duplicate],
            'processing -> succeeded' => [$processing, $succeeded, $advance],
            'processing -> failed' => [$processing, $failed, $advance],

            'succeeded -> queued' => [$succeeded, $queued, $stale],
            'succeeded -> processing' => [$succeeded, $processing, $stale],
            'succeeded -> succeeded' => [$succeeded, $succeeded, $duplicate],
            'succeeded -> failed' => [$succeeded, $failed, $conflict],

            'failed -> queued' => [$failed, $queued, $stale],
            'failed -> processing' => [$failed, $processing, $stale],
            'failed -> succeeded' => [$failed, $succeeded, $conflict],
            'failed -> failed' => [$failed, $failed, $duplicate],
        ];
    }

    #[DataProvider('transitionMatrix')]
    public function test_it_applies_an_observation_to_its_owned_state(
        ?ProviderGenerationStatus $current,
        ProviderGenerationStatus $observed,
        ProviderTransitionOutcome $expected,
    ): void {
        $machine = new ProviderGenerationStateMachine($current);

        $this->assertSame($expected, self::observe($machine, $observed));
        $this->assertSame(
            $expected === ProviderTransitionOutcome::Advance ? $observed : $current,
            $machine->status(),
        );
    }

    /** @return array<string, array{?ProviderGenerationStatus, bool, bool}> */
    public static function guards(): array
    {
        return [
            'no prior observation' => [null, true, true],
            'queued' => [ProviderGenerationStatus::Queued, true, true],
            'processing' => [ProviderGenerationStatus::Processing, false, true],
            'succeeded' => [ProviderGenerationStatus::Succeeded, false, false],
            'failed' => [ProviderGenerationStatus::Failed, false, false],
        ];
    }

    #[DataProvider('guards')]
    public function test_guards_allow_only_new_processing_and_completion_transitions(
        ?ProviderGenerationStatus $status,
        bool $canProcess,
        bool $canComplete,
    ): void {
        $machine = new ProviderGenerationStateMachine($status);

        $this->assertSame($canProcess, $machine->canProcess());
        $this->assertSame($canComplete, $machine->canComplete());
    }

    public function test_the_matrix_covers_every_status_pair_once_and_every_outcome(): void
    {
        $matrix = self::transitionMatrix();
        $statuses = ProviderGenerationStatus::cases();
        $outcomes = ProviderTransitionOutcome::cases();

        $this->assertCount(
            count($statuses) * (count($statuses) + 1),
            $matrix,
            'The matrix must cover every current/observed pair, including no prior observation.',
        );

        $seenPairs = [];
        $observedStatuses = [];
        $currentStatuses = [];
        $seenOutcomes = [];

        foreach ($matrix as $name => [$current, $observed, $expected]) {
            $currentValue = $current === null ? 'none' : $current->value;
            $pair = $currentValue.' -> '.$observed->value;

            $this->assertArrayNotHasKey($pair, $seenPairs, "Duplicate matrix entry for [{$pair}] at [{$name}].");

            $seenPairs[$pair] = true;
            $observedStatuses[$observed->value] = true;
            $currentStatuses[$currentValue] = true;
            $seenOutcomes[$expected->name] = true;
        }

        $this->assertEqualsCanonicalizing(
            array_map(fn (ProviderGenerationStatus $status) => $status->value, $statuses),
            array_keys($observedStatuses),
            'Every provider status must appear as an observed status.',
        );

        $this->assertEqualsCanonicalizing(
            ['none', ...array_map(fn (ProviderGenerationStatus $status) => $status->value, $statuses)],
            array_keys($currentStatuses),
            'Every provider status and the no-prior-observation state must appear as a current status.',
        );

        $this->assertEqualsCanonicalizing(
            array_map(fn (ProviderTransitionOutcome $outcome) => $outcome->name, $outcomes),
            array_keys($seenOutcomes),
            'Every transition outcome must be exercised.',
        );
    }

    public function test_normal_progression_evolves_state_and_capabilities_together(): void
    {
        $machine = new ProviderGenerationStateMachine;

        $this->assertNull($machine->status());
        $this->assertFalse($machine->isTerminal());
        $this->assertTrue($machine->canProcess());
        $this->assertTrue($machine->canComplete());

        $this->assertSame(ProviderTransitionOutcome::Advance, $machine->applyQueued());
        $this->assertSame(ProviderGenerationStatus::Queued, $machine->status());
        $this->assertTrue($machine->canProcess());
        $this->assertTrue($machine->canComplete());

        $this->assertSame(ProviderTransitionOutcome::Advance, $machine->applyProcessing());
        $this->assertSame(ProviderGenerationStatus::Processing, $machine->status());
        $this->assertFalse($machine->canProcess());
        $this->assertTrue($machine->canComplete());

        $this->assertSame(ProviderTransitionOutcome::Advance, $machine->applySucceeded());
        $this->assertSame(ProviderGenerationStatus::Succeeded, $machine->status());
        $this->assertTrue($machine->isTerminal());
        $this->assertTrue($machine->isSucceeded());
        $this->assertFalse($machine->isFailed());
        $this->assertFalse($machine->canProcess());
        $this->assertFalse($machine->canComplete());
    }

    public function test_a_skipped_processing_observation_can_reach_success(): void
    {
        $machine = new ProviderGenerationStateMachine(ProviderGenerationStatus::Queued);

        $this->assertSame(ProviderTransitionOutcome::Advance, $machine->applySucceeded());
        $this->assertSame(ProviderGenerationStatus::Succeeded, $machine->status());
        $this->assertFalse($machine->canProcess());
        $this->assertFalse($machine->canComplete());
    }

    public function test_the_first_observation_may_already_be_terminal_failure(): void
    {
        $machine = new ProviderGenerationStateMachine;

        $this->assertSame(ProviderTransitionOutcome::Advance, $machine->applyFailed());
        $this->assertSame(ProviderGenerationStatus::Failed, $machine->status());
        $this->assertTrue($machine->isTerminal());
        $this->assertTrue($machine->isFailed());
        $this->assertFalse($machine->isSucceeded());
        $this->assertFalse($machine->canProcess());
        $this->assertFalse($machine->canComplete());
    }

    public function test_late_and_duplicate_observations_do_not_change_a_successful_terminal_state(): void
    {
        $machine = new ProviderGenerationStateMachine(ProviderGenerationStatus::Succeeded);

        $this->assertFalse($machine->canComplete(), 'A new completion transition is no longer permitted.');

        $this->assertSame(ProviderTransitionOutcome::Stale, $machine->applyQueued());
        $this->assertSame(ProviderGenerationStatus::Succeeded, $machine->status());

        $this->assertSame(ProviderTransitionOutcome::Stale, $machine->applyProcessing());
        $this->assertSame(ProviderGenerationStatus::Succeeded, $machine->status());

        $this->assertSame(ProviderTransitionOutcome::Duplicate, $machine->applySucceeded());
        $this->assertSame(ProviderGenerationStatus::Succeeded, $machine->status());

        $this->assertSame(ProviderTransitionOutcome::Conflict, $machine->applyFailed());
        $this->assertSame(ProviderGenerationStatus::Succeeded, $machine->status());
    }

    public function test_late_and_duplicate_observations_do_not_change_a_failed_terminal_state(): void
    {
        $machine = new ProviderGenerationStateMachine(ProviderGenerationStatus::Failed);

        $this->assertSame(ProviderTransitionOutcome::Stale, $machine->applyQueued());
        $this->assertSame(ProviderGenerationStatus::Failed, $machine->status());

        $this->assertSame(ProviderTransitionOutcome::Stale, $machine->applyProcessing());
        $this->assertSame(ProviderGenerationStatus::Failed, $machine->status());

        $this->assertSame(ProviderTransitionOutcome::Duplicate, $machine->applyFailed());
        $this->assertSame(ProviderGenerationStatus::Failed, $machine->status());

        $this->assertSame(ProviderTransitionOutcome::Conflict, $machine->applySucceeded());
        $this->assertSame(ProviderGenerationStatus::Failed, $machine->status());
    }

    public function test_hydration_continues_from_the_persisted_status(): void
    {
        $machine = new ProviderGenerationStateMachine(ProviderGenerationStatus::Processing);

        $this->assertSame(ProviderGenerationStatus::Processing, $machine->status());
        $this->assertFalse($machine->isTerminal());
        $this->assertFalse($machine->canProcess());
        $this->assertTrue($machine->canComplete());

        $this->assertSame(ProviderTransitionOutcome::Duplicate, $machine->applyProcessing());
        $this->assertSame(ProviderGenerationStatus::Processing, $machine->status());

        $this->assertSame(ProviderTransitionOutcome::Advance, $machine->applySucceeded());
        $this->assertSame(ProviderGenerationStatus::Succeeded, $machine->status());
    }

    public function test_instances_hold_independent_state(): void
    {
        $queued = new ProviderGenerationStateMachine(ProviderGenerationStatus::Queued);
        $other = new ProviderGenerationStateMachine(ProviderGenerationStatus::Queued);
        $unobserved = new ProviderGenerationStateMachine;

        $this->assertSame(ProviderTransitionOutcome::Advance, $queued->applyProcessing());

        $this->assertSame(ProviderGenerationStatus::Processing, $queued->status());
        $this->assertSame(ProviderGenerationStatus::Queued, $other->status());
        $this->assertNull($unobserved->status());

        $this->assertSame(ProviderTransitionOutcome::Advance, $queued->applySucceeded());

        $this->assertSame(ProviderGenerationStatus::Succeeded, $queued->status());
        $this->assertSame(ProviderGenerationStatus::Queued, $other->status());
        $this->assertTrue($other->canProcess());
    }

    public function test_status_enum_uses_the_exact_provider_wire_values(): void
    {
        $this->assertSame(
            ['queued', 'processing', 'succeeded', 'failed'],
            array_map(fn (ProviderGenerationStatus $status) => $status->value, ProviderGenerationStatus::cases()),
        );
    }

    private static function observe(
        ProviderGenerationStateMachine $machine,
        ProviderGenerationStatus $observed,
    ): ProviderTransitionOutcome {
        return match ($observed) {
            ProviderGenerationStatus::Queued => $machine->applyQueued(),
            ProviderGenerationStatus::Processing => $machine->applyProcessing(),
            ProviderGenerationStatus::Succeeded => $machine->applySucceeded(),
            ProviderGenerationStatus::Failed => $machine->applyFailed(),
        };
    }
}
