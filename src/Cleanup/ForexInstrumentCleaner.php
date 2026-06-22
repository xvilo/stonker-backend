<?php

declare(strict_types=1);

namespace App\Cleanup;

use App\Repository\InstrumentRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Removes forex/currency-pair instruments (e.g. EUR.USD) and their transactions
 * left behind by earlier IBKR imports — before the importer learned to skip
 * them. New imports no longer create these (see {@see \App\Import\CsvColumnTrait::isEquityTrade()}),
 * so this only mops up legacy rows.
 */
final class ForexInstrumentCleaner implements DataCleanerInterface
{
    public function __construct(
        private readonly InstrumentRepository $instruments,
        private readonly TransactionRepository $transactions,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function key(): string
    {
        return 'forex';
    }

    public function description(): string
    {
        return 'Forex/currency-pair instruments (e.g. EUR.USD) and their transactions left behind by earlier IBKR imports';
    }

    public function clean(bool $apply): CleanupReport
    {
        $pairs = $this->instruments->findCurrencyPairs();
        if ([] === $pairs) {
            return CleanupReport::nothing();
        }

        $rows = [];
        $txTotal = 0;
        foreach ($pairs as $instrument) {
            // Transaction -> Instrument has no DB cascade, so remove the
            // transactions first; PriceSnapshots cascade on the instrument delete.
            $txns = $this->transactions->findBy(['instrument' => $instrument]);
            $txTotal += \count($txns);
            $rows[] = [$instrument->getSymbol(), $instrument->getName(), \count($txns)];

            if ($apply) {
                foreach ($txns as $txn) {
                    $this->em->remove($txn);
                }
                $this->em->remove($instrument);
            }
        }

        if ($apply) {
            $this->em->flush();
        }

        return new CleanupReport(
            headers: ['Symbol', 'Name', 'Transactions'],
            rows: $rows,
            summary: sprintf('%d instrument(s) and %d transaction(s)', \count($pairs), $txTotal),
        );
    }
}
