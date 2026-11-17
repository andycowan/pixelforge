<?php

namespace App\Enums;

/**
 * Local submission bookkeeping, deliberately separate from provider status.
 */
enum GenerationSubmissionStatus: string
{
    case Pending = 'pending';
    case Uncertain = 'uncertain';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
