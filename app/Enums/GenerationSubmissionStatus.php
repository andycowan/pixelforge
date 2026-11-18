<?php

namespace App\Enums;

enum GenerationSubmissionStatus: string
{
    case Pending = 'pending';
    case Uncertain = 'uncertain';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
