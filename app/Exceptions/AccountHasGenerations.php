<?php

namespace App\Exceptions;

use DomainException;

final class AccountHasGenerations extends DomainException
{
    public function __construct()
    {
        parent::__construct('Account deletion is unavailable while generation or credit records are retained.');
    }
}
