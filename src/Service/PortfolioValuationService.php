<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\CurrencySeries;
use App\Dto\PerformancePoint;
use App\Entity\Account;
use App\Enum\TransactionType;
use App\Repository\PriceSnapshotRepository;
use App\Repository\TransactionRepository;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Builds a daily performance time series per currency. It walks each day in the
 * range, applies that day's transactions to per-instrument FIFO lots, values
 * the open holdings at the last-known close (carried forward across gaps), and
 * records cost basis, market value and P/L (absolute and %) for each currency.
 *
 * Instruments without a price yet are valued at cost (P/L 0) so the curve never
 * dips artificially before the first quote arrives.
 */
final class PortfolioValuationService
{
    private const MONEY_SCALE = 4;
    private const PCT_SCALE = 4;
    private const DIV_SCALE = 12;
    /** Safety cap on the number of days computed in one request. */
    private const MAX_DAYS = 1500;

    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly PriceSnapshotRepository $prices,
    ) {
    }

    /**
     * @return list<CurrencySeries>
     */
    public function buildSeries(Account $account, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $from = $from->setTime(0, 0);
        $to = $to->setTime(0, 0);
        if ($from > $to) {
            return [];
        }
        if ($from < $to->modify(sprintf('-%d days', self::MAX_DAYS))) {
            $from = $to->modify(sprintf('-%d days', self::MAX_DAYS));
        }

        $txns = $this->transactions->findForAccountOrdered($account);
        if ([] === $txns) {
            return [];
        }

        // Index transactions and collect the instruments + their currencies.
        $instruments = [];
        $currencyOf = [];
        $txRows = [];
        foreach ($txns as $tx) {
            $instrument = $tx->getInstrument();
            $iid = $instrument->getId()->toRfc4122();
            $instruments[$iid] = $instrument;
            $currencyOf[$iid] = $instrument->getCurrency();
            $txRows[] = [
                'date' => $tx->getTradeDate()->format('Y-m-d'),
                'iid' => $iid,
                'type' => $tx->getType(),
                'qty' => $tx->getQuantity(),
                'price' => $tx->getPricePerShare(),
                'fee' => $tx->getFee(),
            ];
        }

        // Per-instrument price history (oldest first) for carry-forward lookup.
        $priceRows = $this->prices->findForInstrumentsUpTo(array_values($instruments), $to);
        $priceHistory = [];
        foreach ($priceRows as $snapshot) {
            $iid = $snapshot->getInstrument()->getId()->toRfc4122();
            $priceHistory[$iid][] = ['date' => $snapshot->getDate()->format('Y-m-d'), 'close' => $snapshot->getClose()];
        }

        $lots = [];          // iid => list<array{qty: BigDecimal, cost: BigDecimal}>
        $priceIdx = [];      // iid => int pointer into $priceHistory[iid]
        $currentPrice = [];  // iid => ?string carried-forward close
        $ti = 0;
        $txCount = \count($txRows);

        /** @var array<string, list<PerformancePoint>> $seriesByCurrency */
        $seriesByCurrency = [];

        for ($cursor = $from; $cursor <= $to; $cursor = $cursor->modify('+1 day')) {
            $day = $cursor->format('Y-m-d');

            // Apply every transaction up to and including this day.
            while ($ti < $txCount && $txRows[$ti]['date'] <= $day) {
                $this->applyTrade($lots, $txRows[$ti]);
                ++$ti;
            }

            // Advance carried-forward prices to this day.
            foreach ($priceHistory as $iid => $history) {
                $idx = $priceIdx[$iid] ?? 0;
                while ($idx < \count($history) && $history[$idx]['date'] <= $day) {
                    $currentPrice[$iid] = $history[$idx]['close'];
                    ++$idx;
                }
                $priceIdx[$iid] = $idx;
            }

            // Value holdings per currency.
            $costByCcy = [];
            $valueByCcy = [];
            foreach ($lots as $iid => $instrumentLots) {
                $ccy = $currencyOf[$iid];
                $qty = BigDecimal::zero();
                $cost = BigDecimal::zero();
                foreach ($instrumentLots as $lot) {
                    $qty = $qty->plus($lot['qty']);
                    $cost = $cost->plus($lot['qty']->multipliedBy($lot['cost']));
                }
                if (!$qty->isPositive()) {
                    continue;
                }
                $price = $currentPrice[$iid] ?? null;
                $value = null !== $price ? $qty->multipliedBy($price) : $cost;

                $costByCcy[$ccy] = ($costByCcy[$ccy] ?? BigDecimal::zero())->plus($cost);
                $valueByCcy[$ccy] = ($valueByCcy[$ccy] ?? BigDecimal::zero())->plus($value);
            }

            foreach ($costByCcy as $ccy => $cost) {
                $value = $valueByCcy[$ccy];
                $pnl = $value->minus($cost);
                $pct = $cost->isPositive()
                    ? (string) $pnl->dividedBy($cost, self::DIV_SCALE, RoundingMode::HalfUp)
                        ->multipliedBy(100)->toScale(self::PCT_SCALE, RoundingMode::HalfUp)
                    : null;

                $seriesByCurrency[$ccy][] = new PerformancePoint(
                    date: $day,
                    costBasis: (string) $cost->toScale(self::MONEY_SCALE, RoundingMode::HalfUp),
                    marketValue: (string) $value->toScale(self::MONEY_SCALE, RoundingMode::HalfUp),
                    pnl: (string) $pnl->toScale(self::MONEY_SCALE, RoundingMode::HalfUp),
                    pnlPct: $pct,
                );
            }
        }

        $series = [];
        foreach ($seriesByCurrency as $ccy => $points) {
            $series[] = new CurrencySeries($ccy, $points);
        }
        usort($series, static fn (CurrencySeries $a, CurrencySeries $b): int => $a->currency <=> $b->currency);

        return $series;
    }

    /**
     * @param array<string, list<array{qty: BigDecimal, cost: BigDecimal}>> $lots
     * @param array{date: string, iid: string, type: TransactionType, qty: string, price: string, fee: string} $tx
     */
    private function applyTrade(array &$lots, array $tx): void
    {
        $iid = $tx['iid'];
        $qty = BigDecimal::of($tx['qty']);
        $price = BigDecimal::of($tx['price']);
        $fee = BigDecimal::of($tx['fee']);
        $lots[$iid] ??= [];

        if (TransactionType::BUY === $tx['type']) {
            if ($qty->isZero()) {
                return;
            }
            $lots[$iid][] = [
                'qty' => $qty,
                'cost' => $qty->multipliedBy($price)->plus($fee)->dividedBy($qty, self::DIV_SCALE, RoundingMode::HalfUp),
            ];

            return;
        }

        $remaining = $qty;
        while ($remaining->isPositive() && [] !== $lots[$iid]) {
            $lot = $lots[$iid][0];
            $take = $lot['qty']->isLessThanOrEqualTo($remaining) ? $lot['qty'] : $remaining;
            $remaining = $remaining->minus($take);
            $left = $lot['qty']->minus($take);
            if ($left->isZero()) {
                array_shift($lots[$iid]);
            } else {
                $lots[$iid][0] = ['qty' => $left, 'cost' => $lot['cost']];
            }
        }
    }
}
