<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Instrument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Instrument>
 */
class InstrumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Instrument::class);
    }

    public function findOneByIsin(string $isin): ?Instrument
    {
        return $this->findOneBy(['isin' => strtoupper($isin)]);
    }

    public function findOneBySymbol(string $symbol): ?Instrument
    {
        return $this->findOneBy(['symbol' => $symbol]);
    }

    /**
     * Instruments that carry an ISIN — candidates for OpenFIGI symbol resolution.
     *
     * @return Instrument[]
     */
    public function findWithIsin(): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.isin IS NOT NULL')
            ->orderBy('i.symbol', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
