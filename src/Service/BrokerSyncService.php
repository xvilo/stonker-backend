<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\BrokerConnection;
use App\Entity\BrokerSyncRun;
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

        // Persist a BrokerSyncRun row for the attempt (success or failure) and
        // append the console-facing summary. Flushing here also saves any
        // lastSyncAt change set on the connection above.
        $record = function (BrokerConnection $connection, array $row) use (&$results): void {
            $this->em->persist(new BrokerSyncRun(
                $connection,
                $row['fetched'],
                $row['imported'],
                $row['skipped'],
                $row['error'],
            ));
            $this->em->flush();
            $results[] = $row;
        };

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
                $record($connection, $row);

                continue;
            }

            $token = (string) ($credentials['token'] ?? '');
            $queryId = (string) ($credentials['queryId'] ?? '');
            if ('' === $token || '' === $queryId) {
                $row['error'] = 'missing token or queryId';
                $record($connection, $row);

                continue;
            }

            $fetch = $this->flexClient->fetchStatement($token, $queryId);
            if (!$fetch->isSuccess()) {
                $row['error'] = $fetch->error();
                $record($connection, $row);

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

            $row['fetched'] = true;
            $row['imported'] = $batch->getRowsImported();
            $row['skipped'] = $batch->getRowsSkipped();
            if ([] !== $batch->getErrors()) {
                $row['error'] = sprintf('%d row error(s)', \count($batch->getErrors()));
            }
            $record($connection, $row);
        }

        return $results;
    }
}
