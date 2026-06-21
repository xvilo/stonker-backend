<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Account;
use App\Entity\ImportBatch;
use App\Entity\Instrument;
use App\Entity\Transaction;
use App\Enum\BrokerType;
use App\Enum\ImportStatus;
use App\Enum\InstrumentType;
use App\Enum\TransactionSource;
use App\Import\BrokerImporterInterface;
use App\Import\ImportException;
use App\Import\ParsedTrade;
use App\Repository\InstrumentRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns a broker export into transactions: picks the importer for the
 * (broker, source) pair, resolves/creates instruments, deduplicates by
 * externalId (idempotent re-imports) and records an ImportBatch audit row.
 */
final class ImportService
{
    /**
     * @param iterable<BrokerImporterInterface> $importers
     */
    public function __construct(
        private readonly iterable $importers,
        private readonly InstrumentRepository $instruments,
        private readonly TransactionRepository $transactions,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function import(Account $account, BrokerType $broker, TransactionSource $source, string $content, ?string $fileName = null): ImportBatch
    {
        $batch = new ImportBatch($account, $broker, $source, $fileName);
        $batch->setStatus(ImportStatus::PROCESSING);
        $this->em->persist($batch);

        $importer = $this->findImporter($broker, $source);
        if (null === $importer) {
            return $this->fail($batch, sprintf('No importer for %s / %s.', $broker->value, $source->value));
        }

        try {
            $parsed = $importer->parse($content);
        } catch (ImportException $e) {
            return $this->fail($batch, $e->getMessage());
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $seen = [];
        $instrumentCache = [];

        foreach ($parsed as $idx => $trade) {
            try {
                $dedupeKey = $trade->externalId;
                if (isset($seen[$dedupeKey]) || null !== $this->transactions->findOneBy([
                    'account' => $account,
                    'brokerType' => $broker,
                    'externalId' => $dedupeKey,
                ])) {
                    ++$skipped;

                    continue;
                }
                $seen[$dedupeKey] = true;

                $instrument = $this->resolveInstrument($trade, $instrumentCache);
                $transaction = new Transaction(
                    $account,
                    $instrument,
                    $broker,
                    $trade->type,
                    $trade->tradeDate,
                    $trade->quantity,
                    $trade->pricePerShare,
                    $trade->currency,
                    $trade->fee,
                    $trade->feeCurrency,
                );
                $transaction->setSource($source);
                $transaction->setExternalId($trade->externalId);
                $this->em->persist($transaction);
                ++$imported;
            } catch (\Throwable $e) {
                $errors[] = sprintf('Row %d (%s): %s', $idx + 1, $trade->symbol, $e->getMessage());
            }
        }

        $batch->setRowsImported($imported)
            ->setRowsSkipped($skipped)
            ->setErrors($errors)
            ->setStatus(ImportStatus::COMPLETED)
            ->setFinishedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $batch;
    }

    /**
     * @param array<string, Instrument> $cache
     */
    private function resolveInstrument(ParsedTrade $trade, array &$cache): Instrument
    {
        $key = $trade->isin ?? $trade->symbol;
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $instrument = (null !== $trade->isin ? $this->instruments->findOneByIsin($trade->isin) : null)
            ?? $this->instruments->findOneBySymbol($trade->symbol);

        if (null === $instrument) {
            $instrument = new Instrument($trade->symbol, $trade->name, InstrumentType::STOCK, $trade->currency, $trade->isin);
            $this->em->persist($instrument);
        }

        return $cache[$key] = $instrument;
    }

    private function findImporter(BrokerType $broker, TransactionSource $source): ?BrokerImporterInterface
    {
        foreach ($this->importers as $importer) {
            if ($importer->getBroker() === $broker && $importer->getSource() === $source) {
                return $importer;
            }
        }

        return null;
    }

    private function fail(ImportBatch $batch, string $message): ImportBatch
    {
        $batch->setStatus(ImportStatus::FAILED)
            ->setErrors([$message])
            ->setFinishedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $batch;
    }
}
