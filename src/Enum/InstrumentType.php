<?php

declare(strict_types=1);

namespace App\Enum;

enum InstrumentType: string
{
    case STOCK = 'STOCK';
    case ETF = 'ETF';
}
