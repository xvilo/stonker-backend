<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\AccountMembership;
use App\Entity\Invitation;
use App\Entity\User;
use App\Enum\InvitationStatus;
use App\Repository\AccountMembershipRepository;
use App\Repository\InvitationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Accepts an invitation by its token: validates it is still pending and that
 * the logged-in user's email matches, then materialises the membership. The
 * acceptance is idempotent if the user is already a member.
 *
 * @implements ProcessorInterface<mixed, Invitation>
 */
final class InvitationAcceptProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly InvitationRepository $invitations,
        private readonly AccountMembershipRepository $memberships,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Invitation
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Authentication required.');
        }

        $token = (string) ($uriVariables['token'] ?? '');
        $invitation = $this->invitations->findOneByToken($token);
        if (null === $invitation) {
            throw new NotFoundHttpException('Invitation not found.');
        }

        if ($invitation->isExpired()) {
            $invitation->setStatus(InvitationStatus::EXPIRED);
            $this->em->flush();
            throw new ConflictHttpException('This invitation has expired.');
        }

        if (!$invitation->isPending()) {
            throw new ConflictHttpException('This invitation is no longer pending.');
        }

        if (strtolower($user->getEmail()) !== $invitation->getEmail()) {
            throw new AccessDeniedHttpException('This invitation was issued to a different email address.');
        }

        $account = $invitation->getAccount();
        if (null === $this->memberships->findOneForAccountAndUser($account, $user)) {
            $this->em->persist(new AccountMembership($account, $user, $invitation->getRole()));
        }

        $invitation->setStatus(InvitationStatus::ACCEPTED);
        $this->em->flush();

        return $invitation;
    }
}
