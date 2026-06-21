<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * A computed holding for one instrument within an account, in the instrument's
 * native currency. All monetary values are decimal strings; market-dependent
 * fields are null when no price snapshot exists.
 */
final readonly class Position
{
    public function __construct(
        public string $instrumentId,
        public string $symbol,
        public ?string $isin,
        public string $name,
        public string $type,
        public string $currency,
        public string $quantity,
        public string $averageCost,
        public string $costBasis,
        public ?string $marketPrice,
        public ?string $marketValue,
        public ?string $unrealizedPnl,
        public ?string $unrealizedPnlPct,
        public string $realizedPnl,
        public ?string $priceAsOf,
    ) {
    }
}
