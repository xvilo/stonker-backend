<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\BrokerSyncTrigger;
use App\Enum\BrokerType;
use App\Repository\BrokerConnectionRepository;
use App\Security\Voter\AccountVoter;
use App\Service\BrokerSyncService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Manual re-run of a single broker connection's sync, independent of the
 * daily cron and the auto-retry job.
 *
 *   POST /api/broker_connections/{connectionId}/sync
 */
final class BrokerConnectionSyncController
{
    public function __construct(
        private readonly BrokerConnectionRepository $connections,
        private readonly AuthorizationCheckerInterface $auth,
        private readonly BrokerSyncService $brokerSync,
    ) {
    }

    #[Route('/api/broker_connections/{connectionId}/sync', name: 'app_broker_connection_sync', methods: ['POST'])]
    public function __invoke(string $connectionId): JsonResponse
    {
        if (!Uuid::isValid($connectionId)) {
            throw new NotFoundHttpException('Connection not found.');
        }

        $connection = $this->connections->find(Uuid::fromString($connectionId));
        if (null === $connection || !$this->auth->isGranted(AccountVoter::VIEW, $connection->getAccount())) {
            throw new NotFoundHttpException('Connection not found.');
        }
        if (!$this->auth->isGranted(AccountVoter::EDIT, $connection->getAccount())) {
            throw new AccessDeniedHttpException('You need edit rights on this account to sync.');
        }
        if (BrokerType::IBKR !== $connection->getBrokerType()) {
            throw new BadRequestHttpException('Manual sync isn\'t supported for this broker — use CSV import.');
        }

        $row = $this->brokerSync->syncConnection($connection, BrokerSyncTrigger::MANUAL);

        return new JsonResponse([
            'fetched' => $row['fetched'],
            'imported' => $row['imported'],
            'skipped' => $row['skipped'],
            'note' => $row['error'],
            'trigger' => BrokerSyncTrigger::MANUAL->value,
            'createdAt' => $row['run']->getCreatedAt()->format(\DATE_ATOM),
        ], JsonResponse::HTTP_CREATED);
    }
}
