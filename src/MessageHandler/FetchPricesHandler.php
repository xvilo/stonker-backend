<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\FetchPricesMessage;
use App\Service\PriceUpdater;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class FetchPricesHandler
{
    public function __construct(private readonly PriceUpdater $priceUpdater)
    {
    }

    public function __invoke(FetchPricesMessage $message): void
    {
        $this->priceUpdater->updateAll();
    }
}
