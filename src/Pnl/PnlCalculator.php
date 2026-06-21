<?php

declare(strict_types=1);

namespace App\Pnl;

use App\Enum\TransactionType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * FIFO cost-basis P/L for a single instrument.
 *
 * Buy fees are folded into the cost basis of the acquired lot; sell fees are
 * subtracted from the proceeds. Sells consume the oldest lots first, so
 * realized P/L = (proceeds net of fee) − (FIFO cost of the shares sold).
 *
 * All arithmetic uses BigDecimal — never floats — to keep money exact.
 */
final class PnlCalculator
{
    /** Working precision for intermediate division (per-share costs, ratios). */
    private const SCALE = 12;
    private const MONEY_SCALE = 4;
    private const QTY_SCALE = 8;
    private const PRICE_SCALE = 8;
    private const PCT_SCALE = 4;

    /**
     * @param iterable<Trade> $trades   in chronological order
     * @param string|null     $marketPrice latest price per share, or null if unknown
     */
    public function calculate(iterable $trades, ?string $marketPrice): PositionPnl
    {
        /** @var list<array{qty: BigDecimal, cost: BigDecimal}> $lots oldest first */
        $lots = [];
        $realized = BigDecimal::zero();

        foreach ($trades as $trade) {
            $qty = BigDecimal::of($trade->quantity);
            $price = BigDecimal::of($trade->pricePerShare);
            $fee = BigDecimal::of($trade->fee);

            if (TransactionType::BUY === $trade->type) {
                if ($qty->isZero()) {
                    continue;
                }
                $totalCost = $qty->multipliedBy($price)->plus($fee);
                $lots[] = [
                    'qty' => $qty,
                    'cost' => $totalCost->dividedBy($qty, self::SCALE, RoundingMode::HalfUp),
                ];

                continue;
            }

            // SELL: proceeds net of fee, FIFO cost of the shares sold.
            $proceeds = $qty->multipliedBy($price)->minus($fee);
            $remaining = $qty;
            $costOfSold = BigDecimal::zero();

            while ($remaining->isPositive() && [] !== $lots) {
                $lot = $lots[0];
                $take = $lot['qty']->isLessThanOrEqualTo($remaining) ? $lot['qty'] : $remaining;
                $costOfSold = $costOfSold->plus($take->multipliedBy($lot['cost']));
                $remaining = $remaining->minus($take);
                $left = $lot['qty']->minus($take);

                if ($left->isZero()) {
                    array_shift($lots);
                } else {
                    $lots[0] = ['qty' => $left, 'cost' => $lot['cost']];
                }
            }

            // If oversold (more sold than held), the excess has zero cost basis.
            $realized = $realized->plus($proceeds->minus($costOfSold));
        }

        $openQty = BigDecimal::zero();
        $costBasis = BigDecimal::zero();
        foreach ($lots as $lot) {
            $openQty = $openQty->plus($lot['qty']);
            $costBasis = $costBasis->plus($lot['qty']->multipliedBy($lot['cost']));
        }

        $averageCost = $openQty->isPositive()
            ? $costBasis->dividedBy($openQty, self::PRICE_SCALE, RoundingMode::HalfUp)
            : BigDecimal::zero();

        $marketPriceDec = null !== $marketPrice ? BigDecimal::of($marketPrice) : null;
        $marketValue = null;
        $unrealized = null;
        $unrealizedPct = null;

        if (null !== $marketPriceDec && $openQty->isPositive()) {
            $marketValue = $openQty->multipliedBy($marketPriceDec);
            $unrealized = $marketValue->minus($costBasis);
            if ($costBasis->isPositive()) {
                $unrealizedPct = $unrealized->dividedBy($costBasis, self::SCALE, RoundingMode::HalfUp)
                    ->multipliedBy(100);
            }
        }

        return new PositionPnl(
            openQuantity: (string) $openQty->toScale(self::QTY_SCALE, RoundingMode::HalfUp),
            costBasis: (string) $costBasis->toScale(self::MONEY_SCALE, RoundingMode::HalfUp),
            averageCost: (string) $averageCost->toScale(self::PRICE_SCALE, RoundingMode::HalfUp),
            realizedPnl: (string) $realized->toScale(self::MONEY_SCALE, RoundingMode::HalfUp),
            marketPrice: null !== $marketPriceDec ? (string) $marketPriceDec->toScale(self::PRICE_SCALE, RoundingMode::HalfUp) : null,
            marketValue: null !== $marketValue ? (string) $marketValue->toScale(self::MONEY_SCALE, RoundingMode::HalfUp) : null,
            unrealizedPnl: null !== $unrealized ? (string) $unrealized->toScale(self::MONEY_SCALE, RoundingMode::HalfUp) : null,
            unrealizedPnlPct: null !== $unrealizedPct ? (string) $unrealizedPct->toScale(self::PCT_SCALE, RoundingMode::HalfUp) : null,
        );
    }
}
