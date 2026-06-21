<?php

declare(strict_types=1);

namespace App\Price;

use App\Entity\Instrument;
use App\Market\EodhdClient;

/**
 * EOD Historical Data (https://eodhd.com) — official, keyed, server-friendly,
 * with native ISIN search and broad EU coverage. The primary price provider.
 *
 * Its search-by-ISIN response carries the latest close, so one request resolves
 * the correct listing (e.g. ASML on Amsterdam in EUR, not a US line) and gives
 * the price. All calls share a 20/day budget via EodhdClient.
 */
final class EodhdPriceProvider implements PriceProviderInterface
{
    public function __construct(private readonly EodhdClient $client)
    {
    }

    public function getName(): string
    {
        return 'eodhd';
    }

    public function supports(Instrument $instrument): bool
    {
        return $this->client->isEnabled() && null !== $instrument->getIsin();
    }

    public function fetchLatest(Instrument $instrument): ?PriceQuote
    {
        $best = $this->client->searchBest((string) $instrument->getIsin(), $instrument->getCurrency());
        if (null === $best || !isset($best['previousClose'])) {
            return null;
        }

        $date = isset($best['previousCloseDate'])
            ? (\DateTimeImmutable::createFromFormat('!Y-m-d', (string) $best['previousCloseDate']) ?: new \DateTimeImmutable('today'))
            : new \DateTimeImmutable('today');

        return new PriceQuote(
            date: $date,
            close: (string) $best['previousClose'],
            currency: strtoupper((string) ($best['Currency'] ?? $instrument->getCurrency())),
        );
    }
}
