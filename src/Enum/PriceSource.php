<?php

declare(strict_types=1);

namespace App\Enum;

enum PriceSource: string
{
    case API = 'API';
    case MANUAL = 'MANUAL';
}
