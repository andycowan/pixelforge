<?php

namespace App\Enums;

/**
 * Credit ledger entry kinds. Amounts are stored unsigned; the type gives the
 * direction: purchases and releases add available credit, holds and debits
 * remove it.
 */
enum CreditEntryType: string
{
    case Purchase = 'purchase';
    case Hold = 'hold';
    case Release = 'release';
    case Debit = 'debit';
}
