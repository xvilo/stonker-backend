<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PositionsReport;
use App\Service\AccountAccess;
use App\Service\PortfolioService;

/**
 * @implements ProviderInterface<PositionsReport>
 */
final class PositionsProvider implements ProviderInterface
{
    public function __construct(
        private readonly AccountAccess $accountAccess,
        private readonly PortfolioService $portfolio,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PositionsReport
    {
        $account = $this->accountAccess->getViewable((string) ($uriVariables['accountId'] ?? ''));

        return new PositionsReport(
            accountId: $account->getId()->toRfc4122(),
            asOf: (new \DateTimeImmutable('today'))->format('Y-m-d'),
            positions: $this->portfolio->buildPositions($account),
        );
    }
}
