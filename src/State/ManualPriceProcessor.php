<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\PriceSnapshot;
use App\Enum\PriceSource;
use App\Repository\PriceSnapshotRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Upserts a manually-entered price by (instrument, date) so re-entering a price
 * for a day overwrites it instead of violating the unique constraint. The
 * source is always forced to MANUAL.
 *
 * @implements ProcessorInterface<PriceSnapshot, PriceSnapshot>
 */
final class ManualPriceProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PriceSnapshotRepository $snapshots,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PriceSnapshot
    {
        \assert($data instanceof PriceSnapshot);

        $existing = $this->snapshots->findOneByInstrumentAndDate($data->getInstrument(), $data->getDate());
        if (null !== $existing) {
            $existing->setClose($data->getClose());
            $existing->setSource(PriceSource::MANUAL);
            $this->em->flush();

            return $existing;
        }

        $data->setSource(PriceSource::MANUAL);
        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }
}
