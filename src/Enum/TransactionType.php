<?php

declare(strict_types=1);

namespace App\Enum;

enum TransactionType: string
{
    case BUY = 'BUY';
    case SELL = 'SELL';
}
