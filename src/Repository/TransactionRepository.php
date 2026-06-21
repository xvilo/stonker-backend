<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Account;
use App\Entity\Instrument;
use App\Entity\Transaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Transaction>
 */
class TransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    /**
     * All transactions for an account in chronological order. The secondary
     * sort on createdAt makes same-day ordering deterministic, which FIFO
     * lot-matching depends on.
     *
     * @return Transaction[]
     */
    public function findForAccountOrdered(Account $account): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('i')
            ->innerJoin('t.instrument', 'i')
            ->andWhere('t.account = :account')
            ->setParameter('account', $account)
            ->orderBy('t.tradeDate', 'ASC')
            ->addOrderBy('t.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Distinct instruments that the account has ever transacted, for valuation
     * and price fetching.
     *
     * @return Instrument[]
     */
    public function findInstrumentsForAccount(Account $account): array
    {
        return $this->createQueryBuilder('t')
            ->select('DISTINCT i')
            ->innerJoin('t.instrument', 'i')
            ->andWhere('t.account = :account')
            ->setParameter('account', $account)
            ->getQuery()
            ->getResult();
    }

    /**
     * Lookup for idempotent imports: does this externally-sourced row exist?
     */
    public function findOneByExternalId(Account $account, string $externalId): ?Transaction
    {
        return $this->findOneBy(['account' => $account, 'externalId' => $externalId]);
    }

    /**
     * Every transacted instrument with the date of its first trade (across all
     * accounts) — the window to backfill historical prices over.
     *
     * @return list<array{instrument: Instrument, from: \DateTimeImmutable}>
     */
    public function findInstrumentsWithEarliestTradeDate(): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('IDENTITY(t.instrument) AS instrumentId', 'MIN(t.tradeDate) AS earliest')
            ->groupBy('t.instrument')
            ->getQuery()
            ->getResult();

        if ([] === $rows) {
            return [];
        }

        $ids = array_map(static fn (array $row): Uuid => Uuid::fromString((string) $row['instrumentId']), $rows);
        $byId = [];
        foreach ($this->getEntityManager()->getRepository(Instrument::class)->findBy(['id' => $ids]) as $instrument) {
            $byId[$instrument->getId()->toRfc4122()] = $instrument;
        }

        $result = [];
        foreach ($rows as $row) {
            $id = (string) $row['instrumentId'];
            if (isset($byId[$id])) {
                $result[] = ['instrument' => $byId[$id], 'from' => new \DateTimeImmutable((string) $row['earliest'])];
            }
        }

        return $result;
    }
}
