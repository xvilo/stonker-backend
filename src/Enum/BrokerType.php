<?php

declare(strict_types=1);

namespace App\Enum;

enum BrokerType: string
{
    case DEGIRO = 'DEGIRO';
    case IBKR = 'IBKR';
    case OTHER = 'OTHER';
}
