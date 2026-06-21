<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Instrument;
use App\Entity\PriceSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PriceSnapshot>
 */
class PriceSnapshotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PriceSnapshot::class);
    }

    public function findLatestForInstrument(Instrument $instrument): ?PriceSnapshot
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.instrument = :instrument')
            ->setParameter('instrument', $instrument)
            ->orderBy('p.date', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findOneByInstrumentAndDate(Instrument $instrument, \DateTimeImmutable $date): ?PriceSnapshot
    {
        return $this->findOneBy(['instrument' => $instrument, 'date' => $date]);
    }

    /**
     * Existing snapshot dates for an instrument in a window, as a set of Y-m-d
     * strings — lets the backfiller insert only missing days (idempotent, and
     * never clobbers a manual entry).
     *
     * @return array<string, true>
     */
    public function findExistingDates(Instrument $instrument, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.date')
            ->andWhere('p.instrument = :instrument')
            ->andWhere('p.date BETWEEN :from AND :to')
            ->setParameter('instrument', $instrument)
            ->setParameter('from', $from->format('Y-m-d'))
            ->setParameter('to', $to->format('Y-m-d'))
            ->getQuery()
            ->getResult();

        $dates = [];
        foreach ($rows as $row) {
            $date = $row['date'];
            $dates[$date instanceof \DateTimeInterface ? $date->format('Y-m-d') : substr((string) $date, 0, 10)] = true;
        }

        return $dates;
    }

    /**
     * Every snapshot for a set of instruments up to and including a date, oldest
     * first. The valuation service walks these per day, carrying the last-known
     * close forward across gaps (weekends, holidays, missing data).
     *
     * @param list<Instrument> $instruments
     *
     * @return PriceSnapshot[]
     */
    public function findForInstrumentsUpTo(array $instruments, \DateTimeImmutable $to): array
    {
        if ([] === $instruments) {
            return [];
        }

        return $this->createQueryBuilder('p')
            ->andWhere('p.instrument IN (:instruments)')
            ->andWhere('p.date <= :to')
            ->setParameter('instruments', $instruments)
            ->setParameter('to', $to->format('Y-m-d'))
            ->orderBy('p.date', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
