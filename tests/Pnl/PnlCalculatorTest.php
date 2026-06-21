<?php

declare(strict_types=1);

namespace App\Tests\Pnl;

use App\Enum\TransactionType;
use App\Pnl\PnlCalculator;
use App\Pnl\Trade;
use PHPUnit\Framework\TestCase;

final class PnlCalculatorTest extends TestCase
{
    private PnlCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new PnlCalculator();
    }

    public function testFifoPartialSellWithFees(): void
    {
        // BUY 10 @100 (+2 fee) -> lot @100.2
        // BUY  5 @110 (+2 fee) -> lot @110.4
        // SELL 6 @120 (-2 fee) -> proceeds 718, FIFO cost 6*100.2 = 601.2
        $trades = [
            new Trade(TransactionType::BUY, '10', '100.00', '2.00'),
            new Trade(TransactionType::BUY, '5', '110.00', '2.00'),
            new Trade(TransactionType::SELL, '6', '120.00', '2.00'),
        ];

        $pnl = $this->calc->calculate($trades, '124');

        self::assertSame('116.8000', $pnl->realizedPnl, 'realized = 718 - 601.2');
        self::assertSame('9.00000000', $pnl->openQuantity, '4 left from lot1 + 5 from lot2');
        self::assertSame('952.8000', $pnl->costBasis, '4*100.2 + 5*110.4');
        self::assertSame('105.86666667', $pnl->averageCost, '952.8 / 9');
        self::assertSame('1116.0000', $pnl->marketValue, '9 * 124');
        self::assertSame('163.2000', $pnl->unrealizedPnl, '1116 - 952.8');
        self::assertSame('17.1285', $pnl->unrealizedPnlPct, '163.2 / 952.8 * 100');
    }

    public function testFullExitLeavesNoOpenPositionAndNoMarketFields(): void
    {
        $trades = [
            new Trade(TransactionType::BUY, '10', '100.00', '0'),
            new Trade(TransactionType::SELL, '10', '150.00', '0'),
        ];

        $pnl = $this->calc->calculate($trades, '200');

        self::assertSame('500.0000', $pnl->realizedPnl);
        self::assertSame('0.00000000', $pnl->openQuantity);
        self::assertSame('0.0000', $pnl->costBasis);
        self::assertNull($pnl->marketValue, 'no open position => no market value');
        self::assertNull($pnl->unrealizedPnl);
        self::assertNull($pnl->unrealizedPnlPct);
    }

    public function testNoMarketPriceYieldsNullUnrealised(): void
    {
        $pnl = $this->calc->calculate([new Trade(TransactionType::BUY, '3', '50', '1')], null);

        self::assertSame('151.0000', $pnl->costBasis, '3*50 + 1');
        self::assertNull($pnl->marketValue);
        self::assertNull($pnl->unrealizedPnl);
        self::assertSame('0.0000', $pnl->realizedPnl);
    }

    public function testFractionalSharesAcrossLots(): void
    {
        // Fractional quantities must stay exact (no float drift).
        $trades = [
            new Trade(TransactionType::BUY, '1.5', '100', '0'),
            new Trade(TransactionType::BUY, '2.5', '120', '0'),
            new Trade(TransactionType::SELL, '2', '130', '0'),
        ];

        $pnl = $this->calc->calculate($trades, '140');

        // Sell 2: 1.5 @100 = 150, then 0.5 @120 = 60 -> cost 210, proceeds 260 -> realized 50
        self::assertSame('50.0000', $pnl->realizedPnl);
        // Remaining 2.0 @120 = 240
        self::assertSame('2.00000000', $pnl->openQuantity);
        self::assertSame('240.0000', $pnl->costBasis);
    }
}
