<?php

namespace App\Enums;

/**
 * Diagnostic result of applying an observed status to the state-owning machine.
 *
 * Only Advance changes the machine's owned status; the other outcomes retain it.
 */
enum ProviderTransitionOutcome
{
    case Advance;
    case Duplicate;
    case Stale;
    case Conflict;
}
