<?php

declare(strict_types=1);

namespace App\Enum;

enum InvitationStatus: string
{
    case PENDING = 'PENDING';
    case ACCEPTED = 'ACCEPTED';
    case REVOKED = 'REVOKED';
    case EXPIRED = 'EXPIRED';
}
