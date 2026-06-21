<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\BrokerType;
use App\Enum\TransactionSource;
use App\Import\IbkrFlexClient;
use App\Repository\BrokerConnectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Pulls trades from every active broker connection (currently IBKR Flex) and
 * imports them. Shared by the scheduled handler and the `app:brokers:sync`
 * console command; returns a per-connection summary for reporting.
 */
final class BrokerSyncService
{
    public function __construct(
        private readonly BrokerConnectionRepository $connections,
        private readonly CredentialEncryption $encryption,
        private readonly IbkrFlexClient $flexClient,
        private readonly ImportService $importService,
        private readonly EntityManagerInterface $em,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return list<array{label: string, broker: string, fetched: bool, imported: int, skipped: int, error: ?string, raw?: ?string, tradeCount?: int}>
     */
    public function syncAll(bool $dump = false): array
    {
        $results = [];

        foreach ($this->connections->findActive() as $connection) {
            if (BrokerType::IBKR !== $connection->getBrokerType()) {
                continue; // DeGiro has no API — CSV/manual only.
            }

            $row = ['label' => $connection->getLabel(), 'broker' => 'IBKR', 'fetched' => false, 'imported' => 0, 'skipped' => 0, 'error' => null];

            try {
                $credentials = $this->encryption->decrypt($connection->getEncryptedCredentials());
            } catch (\Throwable $e) {
                $this->logger?->error('Broker credential decrypt failed', ['connection' => (string) $connection->getId()]);
                $row['error'] = 'could not decrypt credentials';
                $results[] = $row;

                continue;
            }

            $token = (string) ($credentials['token'] ?? '');
            $queryId = (string) ($credentials['queryId'] ?? '');
            if ('' === $token || '' === $queryId) {
                $row['error'] = 'missing token or queryId';
                $results[] = $row;

                continue;
            }

            $fetch = $this->flexClient->fetchStatement($token, $queryId);
            if (!$fetch->isSuccess()) {
                $row['error'] = $fetch->error();
                $results[] = $row;

                continue;
            }

            $statement = $fetch->statement;
            if ($dump) {
                $row['raw'] = $statement;
                $row['tradeCount'] = substr_count($statement, '<Trade ');
            }

            $batch = $this->importService->import(
                $connection->getAccount(),
                BrokerType::IBKR,
                TransactionSource::FLEX,
                $statement,
                'flex-'.$connection->getId(),
            );

            $connection->setLastSyncAt(new \DateTimeImmutable());
            $this->em->flush();

            $row['fetched'] = true;
            $row['imported'] = $batch->getRowsImported();
            $row['skipped'] = $batch->getRowsSkipped();
            if ([] !== $batch->getErrors()) {
                $row['error'] = sprintf('%d row error(s)', \count($batch->getErrors()));
            }
            $results[] = $row;
        }

        return $results;
    }
}
