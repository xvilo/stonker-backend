<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Dto\CurrencySeries;
use App\State\PerformanceProvider;

/**
 * Daily P/L time series per currency for the performance graph:
 * GET /api/accounts/{accountId}/performance?from=YYYY-MM-DD&to=YYYY-MM-DD&currency=EUR
 */
#[ApiResource(
    shortName: 'Performance',
    operations: [
        new Get(
            uriTemplate: '/accounts/{accountId}/performance',
            provider: PerformanceProvider::class,
            openapi: new \ApiPlatform\OpenApi\Model\Operation(
                parameters: [
                    new Parameter(name: 'from', in: 'query', description: 'Start date (YYYY-MM-DD)', required: false),
                    new Parameter(name: 'to', in: 'query', description: 'End date (YYYY-MM-DD)', required: false),
                    new Parameter(name: 'currency', in: 'query', description: 'Filter to a single currency', required: false),
                ],
            ),
        ),
    ],
)]
final class PerformanceReport
{
    /**
     * @param list<CurrencySeries> $series
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $accountId,
        public string $from,
        public string $to,
        public array $series,
    ) {
    }
}
