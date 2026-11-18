<?php

namespace App\Enums;

/**
 * Credit ledger entry kinds. Amounts are stored unsigned; the type gives the
 * direction: purchases, releases and credit adjustments add available credit;
 * holds, debits and debit adjustments remove it.
 */
enum CreditEntryType: string
{
    case Purchase = 'purchase';
    case Hold = 'hold';
    case Release = 'release';
    case Debit = 'debit';
    case CreditAdjustment = 'credit_adjustment';
    case DebitAdjustment = 'debit_adjustment';
}
