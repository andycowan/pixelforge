<?php

namespace App\Enums;

/**
 * Supported output qualities, backed by the provider wire values.
 */
enum GenerationQuality: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
}
