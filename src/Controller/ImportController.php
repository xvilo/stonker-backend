<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\BrokerType;
use App\Enum\TransactionSource;
use App\Security\Voter\AccountVoter;
use App\Service\AccountAccess;
use App\Service\ImportService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * CSV upload endpoint for broker statements (DeGiro / IBKR activity exports).
 *
 *   POST /api/accounts/{accountId}/imports   (multipart/form-data)
 *     - file:   the CSV file
 *     - broker: DEGIRO | IBKR
 *
 * Runs synchronously and returns the resulting ImportBatch summary.
 */
final class ImportController
{
    public function __construct(
        private readonly AccountAccess $accountAccess,
        private readonly AuthorizationCheckerInterface $auth,
        private readonly ImportService $importService,
    ) {
    }

    #[Route('/api/accounts/{accountId}/imports', name: 'app_import_upload', methods: ['POST'])]
    public function __invoke(string $accountId, Request $request): JsonResponse
    {
        $account = $this->accountAccess->getViewable($accountId);
        if (!$this->auth->isGranted(AccountVoter::EDIT, $account)) {
            throw new AccessDeniedHttpException('You need edit rights on this account to import.');
        }

        $broker = BrokerType::tryFrom(strtoupper((string) $request->request->get('broker')));
        if (null === $broker) {
            throw new BadRequestHttpException('Unknown or missing "broker" (expected DEGIRO or IBKR).');
        }

        $file = $request->files->get('file');
        if (null === $file) {
            throw new BadRequestHttpException('No "file" uploaded.');
        }

        $batch = $this->importService->import(
            $account,
            $broker,
            TransactionSource::CSV,
            (string) file_get_contents($file->getPathname()),
            $file->getClientOriginalName(),
        );

        return new JsonResponse([
            'id' => (string) $batch->getId(),
            'accountId' => $account->getId()->toRfc4122(),
            'broker' => $batch->getBrokerType()->value,
            'source' => $batch->getSource()->value,
            'fileName' => $batch->getFileName(),
            'status' => $batch->getStatus()->value,
            'rowsImported' => $batch->getRowsImported(),
            'rowsSkipped' => $batch->getRowsSkipped(),
            'errors' => $batch->getErrors(),
        ], JsonResponse::HTTP_CREATED);
    }
}
