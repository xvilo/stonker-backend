<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Trigger scheduled pulls for all active broker connections (IBKR Flex).
 * Handled in the broker-import phase.
 */
final class RunBrokerImportsMessage
{
}
