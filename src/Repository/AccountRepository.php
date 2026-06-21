<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Account;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Account>
 */
class AccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    /**
     * @return Account[]
     */
    public function findForUser(User $user): array
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.memberships', 'm')
            ->andWhere('m.user = :user')
            ->setParameter('user', $user->getId(), 'uuid')
            ->orderBy('a.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
