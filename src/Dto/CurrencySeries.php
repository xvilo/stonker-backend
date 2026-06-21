<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * The full performance time series for one currency bucket.
 */
final readonly class CurrencySeries
{
    /**
     * @param list<PerformancePoint> $points
     */
    public function __construct(
        public string $currency,
        public array $points,
    ) {
    }
}
