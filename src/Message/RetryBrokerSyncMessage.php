<?php

declare(strict_types=1);

namespace App\Message;

/** Checked every 30 minutes; the handler retries any connection with an open retry window. */
final class RetryBrokerSyncMessage
{
}
