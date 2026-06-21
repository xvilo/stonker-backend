<?php

declare(strict_types=1);

namespace App\Pnl;

/**
 * Computed profit/loss for a single instrument, all values as decimal strings
 * in the instrument's native currency. Percentages are expressed as numbers
 * (e.g. "12.34" meaning 12.34%). Market-dependent fields are null when no price
 * is available or there is no open position.
 */
final readonly class PositionPnl
{
    public function __construct(
        public string $openQuantity,
        public string $costBasis,
        public string $averageCost,
        public string $realizedPnl,
        public ?string $marketPrice,
        public ?string $marketValue,
        public ?string $unrealizedPnl,
        public ?string $unrealizedPnlPct,
    ) {
    }
}
