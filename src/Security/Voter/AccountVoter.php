<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Account;
use App\Entity\User;
use App\Repository\AccountMembershipRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Authorizes actions on an account based on the current user's membership role.
 *
 *  - VIEW   : any member (OWNER, EDITOR, VIEWER)
 *  - EDIT   : OWNER or EDITOR (record/import/modify transactions)
 *  - MANAGE : OWNER (members, invitations, settings)
 *
 * @extends Voter<string, Account>
 */
final class AccountVoter extends Voter
{
    public const VIEW = 'VIEW';
    public const EDIT = 'EDIT';
    public const MANAGE = 'MANAGE';

    public function __construct(private readonly AccountMembershipRepository $memberships)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT, self::MANAGE], true)
            && $subject instanceof Account;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        \assert($subject instanceof Account);
        $membership = $this->memberships->findOneForAccountAndUser($subject, $user);
        if (null === $membership) {
            return false;
        }

        return match ($attribute) {
            self::VIEW => true,
            self::EDIT => $membership->getRole()->canWrite(),
            self::MANAGE => $membership->getRole()->canManage(),
            default => false,
        };
    }
}
