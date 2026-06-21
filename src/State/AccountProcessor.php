<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Account;
use App\Entity\AccountMembership;
use App\Entity\User;
use App\Enum\MembershipRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Persists a newly created account and makes the creating user its OWNER, so a
 * fresh account is never left without an administrator.
 *
 * @implements ProcessorInterface<Account, Account>
 */
final class AccountProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Account
    {
        \assert($data instanceof Account);

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Authentication required.');
        }

        if (!$data->hasMember($user)) {
            $data->addMembership(new AccountMembership($data, $user, MembershipRole::OWNER));
        }

        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }
}
