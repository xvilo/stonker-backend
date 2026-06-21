<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PnlReport;
use App\Service\AccountAccess;
use App\Service\PortfolioService;

/**
 * @implements ProviderInterface<PnlReport>
 */
final class PnlProvider implements ProviderInterface
{
    public function __construct(
        private readonly AccountAccess $accountAccess,
        private readonly PortfolioService $portfolio,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PnlReport
    {
        $account = $this->accountAccess->getViewable((string) ($uriVariables['accountId'] ?? ''));

        return new PnlReport(
            accountId: $account->getId()->toRfc4122(),
            asOf: (new \DateTimeImmutable('today'))->format('Y-m-d'),
            buckets: $this->portfolio->buildBuckets($account),
        );
    }
}
