<?php

namespace App\Enums;

enum ProviderTransitionOutcome
{
    case Advance;
    case Duplicate;
    case Stale;
    case Conflict;
}
