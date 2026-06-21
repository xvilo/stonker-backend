<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Account;
use App\Entity\AccountMembership;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccountMembership>
 */
class AccountMembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountMembership::class);
    }

    public function findOneForAccountAndUser(Account $account, User $user): ?AccountMembership
    {
        return $this->findOneBy(['account' => $account, 'user' => $user]);
    }
}
