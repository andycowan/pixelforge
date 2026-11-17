<?php

namespace App\Enums;

/**
 * Provider generation statuses, backed by the exact provider wire values.
 */
enum ProviderGenerationStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
