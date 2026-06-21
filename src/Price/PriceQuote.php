<?php

declare(strict_types=1);

namespace App\Price;

/** A single price observation returned by a price provider. */
final readonly class PriceQuote
{
    public function __construct(
        public \DateTimeImmutable $date,
        public string $close,
        public string $currency,
    ) {
    }
}
