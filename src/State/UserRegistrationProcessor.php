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
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Registers a new user: hashes the password and bootstraps a personal account
 * with the user as OWNER, so they have somewhere to record transactions
 * immediately after signing up.
 *
 * @implements ProcessorInterface<User, User>
 */
final class UserRegistrationProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): User
    {
        \assert($data instanceof User);

        $data->setEmail(strtolower($data->getEmail()));
        $data->setPassword($this->hasher->hashPassword($data, (string) $data->getPlainPassword()));
        $data->eraseCredentials();

        $account = new Account(sprintf("%s's portfolio", $data->getName()));
        $account->addMembership(new AccountMembership($account, $data, MembershipRole::OWNER));

        $this->em->persist($data);
        $this->em->persist($account);
        $this->em->flush();

        return $data;
    }
}
