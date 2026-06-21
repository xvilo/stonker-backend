<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\RunBrokerImportsMessage;
use App\Service\BrokerSyncService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Scheduled pull of broker statements (IBKR Flex). The work lives in
 * BrokerSyncService so the `app:brokers:sync` command can trigger it on demand.
 */
#[AsMessageHandler]
final class RunBrokerImportsHandler
{
    public function __construct(private readonly BrokerSyncService $brokerSync)
    {
    }

    public function __invoke(RunBrokerImportsMessage $message): void
    {
        $this->brokerSync->syncAll();
    }
}
