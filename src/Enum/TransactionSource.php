<?php

declare(strict_types=1);

namespace App\Enum;

/** How a transaction entered the system. */
enum TransactionSource: string
{
    case MANUAL = 'MANUAL';
    case CSV = 'CSV';
    case FLEX = 'FLEX';
}
