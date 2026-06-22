<?php

declare(strict_types=1);

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Account;
use App\Entity\AccountMembership;
use App\Entity\BrokerConnection;
use App\Entity\BrokerSyncRun;
use App\Entity\ImportBatch;
use App\Entity\Invitation;
use App\Entity\Transaction;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Multi-tenancy guard at the data layer: every collection and item query for an
 * account-scoped resource is narrowed to accounts the current user belongs to.
 * Non-members simply don't see the rows (404 on items), independent of any
 * operation-level security expression.
 *
 * Instrument and PriceSnapshot are a shared global catalog and are not scoped.
 */
final class CurrentUserExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    /**
     * Maps a resource class to the DQL path of its Account, relative to the
     * query root alias ('' means the root itself is the Account).
     */
    private const ACCOUNT_PATH = [
        Account::class => '',
        Transaction::class => '.account',
        BrokerConnection::class => '.account',
        BrokerSyncRun::class => '.account',
        ImportBatch::class => '.account',
        Invitation::class => '.account',
    ];

    public function __construct(private readonly Security $security)
    {
    }

    public function applyToCollection(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, ?Operation $operation = null, array $context = []): void
    {
        $this->scope($queryBuilder, $resourceClass);
    }

    public function applyToItem(QueryBuilder $queryBuilder, QueryNameGeneratorInterface $queryNameGenerator, string $resourceClass, array $identifiers, ?Operation $operation = null, array $context = []): void
    {
        $this->scope($queryBuilder, $resourceClass);
    }

    private function scope(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        if (!\array_key_exists($resourceClass, self::ACCOUNT_PATH)) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            // No authenticated user => return nothing rather than everything.
            $queryBuilder->andWhere('1 = 0');

            return;
        }

        if ($this->security->isGranted('ROLE_ADMIN')) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $accountExpr = $rootAlias.self::ACCOUNT_PATH[$resourceClass];
        $param = 'current_user_'.spl_object_id($queryBuilder);

        $queryBuilder
            ->andWhere(sprintf(
                'EXISTS (SELECT 1 FROM %s mem WHERE mem.account = %s AND mem.user = :%s)',
                AccountMembership::class,
                $accountExpr,
                $param,
            ))
            ->setParameter($param, $user);
    }
}
