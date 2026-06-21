<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Invitation;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Stamps a new invitation with its creator and persists it. The unique token
 * generated in the constructor is what the invitee uses to accept.
 *
 * @implements ProcessorInterface<Invitation, Invitation>
 */
final class InvitationCreateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Invitation
    {
        \assert($data instanceof Invitation);

        $user = $this->security->getUser();
        if ($user instanceof User) {
            $data->setInvitedBy($user);
        }

        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }
}
