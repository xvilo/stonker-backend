<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * A user's role within an account. OWNER manages members and settings,
 * EDITOR can record transactions, VIEWER has read-only access.
 */
enum MembershipRole: string
{
    case OWNER = 'OWNER';
    case EDITOR = 'EDITOR';
    case VIEWER = 'VIEWER';

    /** Roles allowed to mutate account data (transactions, imports, connections). */
    public function canWrite(): bool
    {
        return $this === self::OWNER || $this === self::EDITOR;
    }

    /** Roles allowed to manage members, invitations and account settings. */
    public function canManage(): bool
    {
        return $this === self::OWNER;
    }
}
