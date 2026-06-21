<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Aggregated P/L for all holdings sharing one currency. Because the app tracks
 * natively (no FX), totals are reported per currency rather than combined.
 */
final readonly class CurrencyBucket
{
    public function __construct(
        public string $currency,
        public string $costBasis,
        public string $marketValue,
        public string $unrealizedPnl,
        public ?string $unrealizedPnlPct,
        public string $realizedPnl,
        public string $totalPnl,
    ) {
    }
}
