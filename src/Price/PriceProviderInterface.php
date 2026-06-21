<?php

declare(strict_types=1);

namespace App\Price;

use App\Entity\Instrument;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Pluggable source of market prices. Implementations are tried in priority
 * order by PriceUpdater; the first that supports an instrument and returns a
 * quote wins. When none does, the instrument keeps its manually-entered
 * snapshots — the always-available fallback.
 */
#[AutoconfigureTag('app.price_provider')]
interface PriceProviderInterface
{
    public function getName(): string;

    public function supports(Instrument $instrument): bool;

    public function fetchLatest(Instrument $instrument): ?PriceQuote;
}
