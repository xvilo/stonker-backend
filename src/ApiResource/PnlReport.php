<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Dto\CurrencyBucket;
use App\State\PnlProvider;

/**
 * Per-currency P/L summary: GET /api/accounts/{accountId}/pnl.
 * Buckets are reported separately per currency (no FX conversion).
 */
#[ApiResource(
    shortName: 'Pnl',
    operations: [
        new Get(
            uriTemplate: '/accounts/{accountId}/pnl',
            provider: PnlProvider::class,
        ),
    ],
)]
final class PnlReport
{
    /**
     * @param list<CurrencyBucket> $buckets
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $accountId,
        public string $asOf,
        public array $buckets,
    ) {
    }
}
