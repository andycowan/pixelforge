<?php

namespace App\Enums;

enum CreditEntryType: string
{
    case Purchase = 'purchase';
    case Hold = 'hold';
    case Release = 'release';
    case Debit = 'debit';
    case CreditAdjustment = 'credit_adjustment';
    case DebitAdjustment = 'debit_adjustment';
}
