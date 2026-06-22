<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BrokerConnection;
use App\Entity\BrokerSyncRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BrokerSyncRun>
 */
class BrokerSyncRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BrokerSyncRun::class);
    }

    /**
     * @return BrokerSyncRun[]
     */
    public function findRecentForConnection(BrokerConnection $connection, int $limit = 20): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.brokerConnection = :connection')
            ->setParameter('connection', $connection)
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
