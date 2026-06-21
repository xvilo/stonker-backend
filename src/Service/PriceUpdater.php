<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Instrument;
use App\Entity\PriceSnapshot;
use App\Enum\PriceSource;
use App\Price\PriceProviderInterface;
use App\Repository\InstrumentRepository;
use App\Repository\PriceSnapshotRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fetches the latest price for each catalogued instrument via the provider
 * chain and upserts a PriceSnapshot. Instruments no provider can price are
 * left to their manually-entered snapshots.
 */
final class PriceUpdater
{
    /**
     * @param iterable<PriceProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers,
        private readonly InstrumentRepository $instruments,
        private readonly PriceSnapshotRepository $snapshots,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{updated: int, skipped: int}
     */
    public function updateAll(): array
    {
        $updated = 0;
        $skipped = 0;

        foreach ($this->instruments->findAll() as $instrument) {
            $quote = $this->fetch($instrument);
            if (null === $quote) {
                ++$skipped;

                continue;
            }

            $existing = $this->snapshots->findOneByInstrumentAndDate($instrument, $quote->date);
            if (null !== $existing) {
                $existing->setClose($quote->close);
                $existing->setSource(PriceSource::API);
            } else {
                $this->em->persist(new PriceSnapshot($instrument, $quote->date, $quote->close, PriceSource::API, $quote->currency));
            }
            ++$updated;
        }

        $this->em->flush();

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    private function fetch(Instrument $instrument): ?\App\Price\PriceQuote
    {
        foreach ($this->providers as $provider) {
            if (!$provider->supports($instrument)) {
                continue;
            }
            $quote = $provider->fetchLatest($instrument);
            if (null !== $quote) {
                return $quote;
            }
        }

        return null;
    }
}
