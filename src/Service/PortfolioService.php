<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\CurrencyBucket;
use App\Dto\Position;
use App\Entity\Account;
use App\Pnl\PnlCalculator;
use App\Pnl\Trade;
use App\Repository\PriceSnapshotRepository;
use App\Repository\TransactionRepository;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Builds the current holdings and per-currency P/L summary for an account by
 * replaying its transactions through the FIFO PnlCalculator and valuing open
 * positions at the latest price snapshot.
 */
final class PortfolioService
{
    private const MONEY_SCALE = 4;
    private const PCT_SCALE = 4;
    private const DIV_SCALE = 12;

    public function __construct(
        private readonly TransactionRepository $transactions,
        private readonly PriceSnapshotRepository $prices,
        private readonly PnlCalculator $calculator,
    ) {
    }

    /**
     * @return list<Position>
     */
    public function buildPositions(Account $account): array
    {
        /** @var array<string, array{instrument: \App\Entity\Instrument, trades: list<Trade>}> $grouped */
        $grouped = [];
        foreach ($this->transactions->findForAccountOrdered($account) as $tx) {
            $key = $tx->getInstrument()->getId()->toRfc4122();
            $grouped[$key]['instrument'] = $tx->getInstrument();
            $grouped[$key]['trades'][] = new Trade(
                $tx->getType(),
                $tx->getQuantity(),
                $tx->getPricePerShare(),
                $tx->getFee(),
            );
        }

        $positions = [];
        foreach ($grouped as $key => $group) {
            $instrument = $group['instrument'];
            $latest = $this->prices->findLatestForInstrument($instrument);
            $pnl = $this->calculator->calculate($group['trades'], $latest?->getClose());

            $positions[] = new Position(
                instrumentId: $key,
                symbol: $instrument->getSymbol(),
                isin: $instrument->getIsin(),
                name: $instrument->getName(),
                type: $instrument->getType()->value,
                currency: $instrument->getCurrency(),
                quantity: $pnl->openQuantity,
                averageCost: $pnl->averageCost,
                costBasis: $pnl->costBasis,
                marketPrice: $pnl->marketPrice,
                marketValue: $pnl->marketValue,
                unrealizedPnl: $pnl->unrealizedPnl,
                unrealizedPnlPct: $pnl->unrealizedPnlPct,
                realizedPnl: $pnl->realizedPnl,
                priceAsOf: $latest?->getDate()->format('Y-m-d'),
            );
        }

        usort($positions, static fn (Position $a, Position $b): int => $a->symbol <=> $b->symbol);

        return $positions;
    }

    /**
     * Per-currency aggregation of the positions above. Closed positions still
     * contribute their realized P/L to the bucket.
     *
     * @return list<CurrencyBucket>
     */
    public function buildBuckets(Account $account): array
    {
        /** @var array<string, array{costBasis: BigDecimal, marketValue: BigDecimal, unrealized: BigDecimal, realized: BigDecimal}> $sums */
        $sums = [];
        foreach ($this->buildPositions($account) as $p) {
            $ccy = $p->currency;
            $sums[$ccy] ??= [
                'costBasis' => BigDecimal::zero(),
                'marketValue' => BigDecimal::zero(),
                'unrealized' => BigDecimal::zero(),
                'realized' => BigDecimal::zero(),
            ];
            $sums[$ccy]['costBasis'] = $sums[$ccy]['costBasis']->plus($p->costBasis);
            $sums[$ccy]['marketValue'] = $sums[$ccy]['marketValue']->plus($p->marketValue ?? '0');
            $sums[$ccy]['unrealized'] = $sums[$ccy]['unrealized']->plus($p->unrealizedPnl ?? '0');
            $sums[$ccy]['realized'] = $sums[$ccy]['realized']->plus($p->realizedPnl);
        }

        $buckets = [];
        foreach ($sums as $ccy => $s) {
            $costBasis = $s['costBasis'];
            $unrealizedPct = $costBasis->isPositive()
                ? (string) $s['unrealized']->dividedBy($costBasis, self::DIV_SCALE, RoundingMode::HalfUp)
                    ->multipliedBy(100)->toScale(self::PCT_SCALE, RoundingMode::HalfUp)
                : null;

            $buckets[] = new CurrencyBucket(
                currency: $ccy,
                costBasis: (string) $costBasis->toScale(self::MONEY_SCALE, RoundingMode::HalfUp),
                marketValue: (string) $s['marketValue']->toScale(self::MONEY_SCALE, RoundingMode::HalfUp),
                unrealizedPnl: (string) $s['unrealized']->toScale(self::MONEY_SCALE, RoundingMode::HalfUp),
                unrealizedPnlPct: $unrealizedPct,
                realizedPnl: (string) $s['realized']->toScale(self::MONEY_SCALE, RoundingMode::HalfUp),
                totalPnl: (string) $s['unrealized']->plus($s['realized'])->toScale(self::MONEY_SCALE, RoundingMode::HalfUp),
            );
        }

        usort($buckets, static fn (CurrencyBucket $a, CurrencyBucket $b): int => $a->currency <=> $b->currency);

        return $buckets;
    }
}
