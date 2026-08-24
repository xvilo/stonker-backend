<?php

declare(strict_types=1);

namespace App\Enum;

/** What caused a BrokerSyncRun attempt: the daily cron, a user click, or the auto-retry job. */
enum BrokerSyncTrigger: string
{
    case SCHEDULED = 'SCHEDULED';
    case MANUAL = 'MANUAL';
    case RETRY = 'RETRY';
}
