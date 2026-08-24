<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BrokerConnection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BrokerConnection>
 */
class BrokerConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BrokerConnection::class);
    }

    /**
     * @return BrokerConnection[]
     */
    public function findActive(): array
    {
        return $this->findBy(['active' => true]);
    }

    /**
     * Active connections with an open retry window (a prior sync failed and
     * fewer than 6h have passed since).
     *
     * @return BrokerConnection[]
     */
    public function findDueForRetry(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.active = true')
            ->andWhere('c.retryUntil IS NOT NULL')
            ->andWhere('c.retryUntil > :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }
}
