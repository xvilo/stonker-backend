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
}
