<?php

declare(strict_types=1);

namespace App\Dto;

/** One day on the performance curve for a currency bucket. */
final readonly class PerformancePoint
{
    public function __construct(
        public string $date,
        public string $costBasis,
        public string $marketValue,
        public string $pnl,
        public ?string $pnlPct,
    ) {
    }
}
