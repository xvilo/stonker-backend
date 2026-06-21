<?php

declare(strict_types=1);

namespace App\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Dto\Position;
use App\State\PositionsProvider;

/**
 * Current holdings for an account: GET /api/accounts/{accountId}/positions.
 * A computed read model (not a database table) provided by PositionsProvider.
 */
#[ApiResource(
    shortName: 'Positions',
    operations: [
        new Get(
            uriTemplate: '/accounts/{accountId}/positions',
            provider: PositionsProvider::class,
        ),
    ],
)]
final class PositionsReport
{
    /**
     * @param list<Position> $positions
     */
    public function __construct(
        #[ApiProperty(identifier: true)]
        public string $accountId,
        public string $asOf,
        public array $positions,
    ) {
    }
}
