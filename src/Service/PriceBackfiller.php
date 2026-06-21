<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PriceSnapshot;
use App\Enum\PriceSource;
use App\Market\EodhdClient;
use App\Repository\PriceSnapshotRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Backfills daily historical prices (from EODHD) from each instrument's first
 * trade date to today, so the performance graph shows real growth over time
 * instead of a flat carried-forward line. One EOD call per instrument fills its
 * whole history; existing dates (incl. manual entries) are left untouched.
 */
final class PriceBackfiller
{
    public function __construct(
        private readonly EodhdClient $client,
        private readonly TransactionRepository $transactions,
        private readonly PriceSnapshotRepository $snapshots,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param \DateTimeImmutable|null $minFrom clamp how far back to go (optional)
     *
     * @return list<array{symbol: string, isin: ?string, from: string, to: string, inserted: int, status: string}>
     */
    public function backfill(?\DateTimeImmutable $minFrom = null): array
    {
        if (!$this->client->isEnabled()) {
            return [];
        }

        $to = new \DateTimeImmutable('today');
        $report = [];

        foreach ($this->transactions->findInstrumentsWithEarliestTradeDate() as $entry) {
            $instrument = $entry['instrument'];
            $from = $entry['from'];
            if (null !== $minFrom && $from < $minFrom) {
                $from = $minFrom;
            }

            $isin = $instrument->getIsin();
            if (null === $isin) {
                $report[] = $this->row($instrument->getSymbol(), null, $from, $to, 0, 'no ISIN');

                continue;
            }

            $symbol = $this->client->resolveSymbol($isin, $instrument->getCurrency());
            if (null === $symbol) {
                $report[] = $this->row($instrument->getSymbol(), $isin, $from, $to, 0, 'no symbol (daily cap or no match)');

                continue;
            }

            $bars = $this->client->eod($symbol, $from, $to);
            if ([] === $bars) {
                $report[] = $this->row($symbol, $isin, $from, $to, 0, 'no data (daily cap or empty)');

                continue;
            }

            $existing = $this->snapshots->findExistingDates($instrument, $from, $to);
            $inserted = 0;
            foreach ($bars as $bar) {
                $date = $bar['date'] ?? null;
                $close = $bar['close'] ?? null;
                if (!\is_string($date) || null === $close || isset($existing[$date])) {
                    continue;
                }
                $this->em->persist(new PriceSnapshot($instrument, new \DateTimeImmutable($date), (string) $close, PriceSource::API));
                $existing[$date] = true;
                ++$inserted;
            }
            $this->em->flush();

            $report[] = $this->row($symbol, $isin, $from, $to, $inserted, 'ok');
        }

        return $report;
    }

    /**
     * @return array{symbol: string, isin: ?string, from: string, to: string, inserted: int, status: string}
     */
    private function row(string $symbol, ?string $isin, \DateTimeImmutable $from, \DateTimeImmutable $to, int $inserted, string $status): array
    {
        return [
            'symbol' => $symbol,
            'isin' => $isin,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'inserted' => $inserted,
            'status' => $status,
        ];
    }
}
