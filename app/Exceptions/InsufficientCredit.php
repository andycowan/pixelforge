<?php

namespace App\Exceptions;

use DomainException;

final class InsufficientCredit extends DomainException
{
    public function __construct(
        public readonly int $requiredNanodollars,
        public readonly int $availableNanodollars,
    ) {
        parent::__construct('Available credit does not cover the maximum price.');
    }
}
