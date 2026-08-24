<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Enum\BrokerSyncTrigger;
use App\Message\RetryBrokerSyncMessage;
use App\Repository\BrokerConnectionRepository;
use App\Service\BrokerSyncService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs every 30 minutes (see Schedule.php); retries any connection whose last
 * sync failed and is still within its 6h retry window.
 */
#[AsMessageHandler]
final class RetryBrokerSyncHandler
{
    public function __construct(
        private readonly BrokerConnectionRepository $connections,
        private readonly BrokerSyncService $brokerSync,
    ) {
    }

    public function __invoke(RetryBrokerSyncMessage $message): void
    {
        foreach ($this->connections->findDueForRetry(new \DateTimeImmutable()) as $connection) {
            $this->brokerSync->syncConnection($connection, BrokerSyncTrigger::RETRY);
        }
    }
}
