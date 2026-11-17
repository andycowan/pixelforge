<?php

namespace App\StateMachines;

use App\Enums\ProviderGenerationStatus;
use App\Enums\ProviderTransitionOutcome;

/**
 * Create one instance per generation from the locked model's current state.
 * Guards describe new transitions, not whether duplicate delivery is safe.
 * Payload validation and atomic settlement remain caller responsibilities.
 */
final class ProviderGenerationStateMachine
{
    private ?ProviderGenerationStatus $status;

    public function __construct(?ProviderGenerationStatus $status = null)
    {
        $this->status = $status;
    }

    public function status(): ?ProviderGenerationStatus
    {
        return $this->status;
    }

    public function isTerminal(): bool
    {
        return $this->status === ProviderGenerationStatus::Succeeded
            || $this->status === ProviderGenerationStatus::Failed;
    }

    public function isSucceeded(): bool
    {
        return $this->status === ProviderGenerationStatus::Succeeded;
    }

    public function isFailed(): bool
    {
        return $this->status === ProviderGenerationStatus::Failed;
    }

    public function canProcess(): bool
    {
        return $this->classify(ProviderGenerationStatus::Processing) === ProviderTransitionOutcome::Advance;
    }

    public function canComplete(): bool
    {
        return $this->classify(ProviderGenerationStatus::Succeeded) === ProviderTransitionOutcome::Advance;
    }

    public function applyQueued(): ProviderTransitionOutcome
    {
        return $this->apply(ProviderGenerationStatus::Queued);
    }

    public function applyProcessing(): ProviderTransitionOutcome
    {
        return $this->apply(ProviderGenerationStatus::Processing);
    }

    public function applySucceeded(): ProviderTransitionOutcome
    {
        return $this->apply(ProviderGenerationStatus::Succeeded);
    }

    public function applyFailed(): ProviderTransitionOutcome
    {
        return $this->apply(ProviderGenerationStatus::Failed);
    }

    private function apply(ProviderGenerationStatus $observed): ProviderTransitionOutcome
    {
        $outcome = $this->classify($observed);

        if ($outcome === ProviderTransitionOutcome::Advance) {
            $this->status = $observed;
        }

        return $outcome;
    }

    // No default: added statuses require explicit transition review.
    private function classify(ProviderGenerationStatus $observed): ProviderTransitionOutcome
    {
        if ($this->status === null) {
            return ProviderTransitionOutcome::Advance;
        }

        return match ($this->status) {
            ProviderGenerationStatus::Queued => match ($observed) {
                ProviderGenerationStatus::Queued => ProviderTransitionOutcome::Duplicate,
                ProviderGenerationStatus::Processing,
                ProviderGenerationStatus::Succeeded,
                ProviderGenerationStatus::Failed => ProviderTransitionOutcome::Advance,
            },
            ProviderGenerationStatus::Processing => match ($observed) {
                ProviderGenerationStatus::Queued => ProviderTransitionOutcome::Stale,
                ProviderGenerationStatus::Processing => ProviderTransitionOutcome::Duplicate,
                ProviderGenerationStatus::Succeeded,
                ProviderGenerationStatus::Failed => ProviderTransitionOutcome::Advance,
            },
            ProviderGenerationStatus::Succeeded => match ($observed) {
                ProviderGenerationStatus::Queued,
                ProviderGenerationStatus::Processing => ProviderTransitionOutcome::Stale,
                ProviderGenerationStatus::Succeeded => ProviderTransitionOutcome::Duplicate,
                ProviderGenerationStatus::Failed => ProviderTransitionOutcome::Conflict,
            },
            ProviderGenerationStatus::Failed => match ($observed) {
                ProviderGenerationStatus::Queued,
                ProviderGenerationStatus::Processing => ProviderTransitionOutcome::Stale,
                ProviderGenerationStatus::Succeeded => ProviderTransitionOutcome::Conflict,
                ProviderGenerationStatus::Failed => ProviderTransitionOutcome::Duplicate,
            },
        };
    }
}
