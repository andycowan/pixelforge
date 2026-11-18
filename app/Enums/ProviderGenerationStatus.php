<?php

namespace App\Enums;

enum ProviderGenerationStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
