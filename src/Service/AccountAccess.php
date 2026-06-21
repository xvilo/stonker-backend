<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Account;
use App\Repository\AccountRepository;
use App\Security\Voter\AccountVoter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Resolves an account id from a request path and enforces that the current user
 * may view it. Used by the computed read-model providers (positions, P/L,
 * performance) which sit on /accounts/{accountId}/... routes. A missing account
 * and a forbidden one both yield 404 so existence is never leaked.
 */
final class AccountAccess
{
    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly AuthorizationCheckerInterface $auth,
    ) {
    }

    public function getViewable(string $id): Account
    {
        if (!Uuid::isValid($id)) {
            throw new NotFoundHttpException('Account not found.');
        }

        $account = $this->accounts->find(Uuid::fromString($id));
        if (null === $account || !$this->auth->isGranted(AccountVoter::VIEW, $account)) {
            throw new NotFoundHttpException('Account not found.');
        }

        return $account;
    }
}
