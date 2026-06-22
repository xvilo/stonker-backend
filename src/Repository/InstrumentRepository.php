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
     * Instruments whose symbol is an FX currency pair (e.g. "EUR.USD"). These
     * sneak in from IBKR forex rows. We pre-filter on the dot in SQL, then
     * narrow with the exact "XXX.YYY" shape in PHP — DB regex isn't portable,
     * and this avoids matching tickers like "BRK.B".
     *
     * @return Instrument[]
     */
    public function findCurrencyPairs(): array
    {
        $candidates = $this->createQueryBuilder('i')
            ->andWhere('i.symbol LIKE :dot')
            ->setParameter('dot', '%.%')
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $candidates,
            static fn (Instrument $i): bool => 1 === preg_match('/^[A-Z]{3}\.[A-Z]{3}$/', $i->getSymbol()),
        ));
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
