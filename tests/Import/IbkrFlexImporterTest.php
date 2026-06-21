<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Enum\TransactionType;
use App\Import\IbkrFlexImporter;
use App\Import\ImportException;
use PHPUnit\Framework\TestCase;

final class IbkrFlexImporterTest extends TestCase
{
    private IbkrFlexImporter $importer;

    protected function setUp(): void
    {
        $this->importer = new IbkrFlexImporter();
    }

    public function testParsesTradesFromStatement(): void
    {
        $xml = <<<XML
            <FlexQueryResponse>
              <FlexStatements count="1">
                <FlexStatement accountId="U123">
                  <Trades>
                    <Trade symbol="NVDA" isin="US67066G1040" tradeID="555" tradeDate="20250310" quantity="10" tradePrice="120.50" ibCommission="-1.00" currency="USD" buySell="BUY" description="NVIDIA CORP"/>
                    <Trade symbol="NVDA" isin="US67066G1040" tradeID="556" tradeDate="20250401" quantity="-4" tradePrice="135.00" ibCommission="-1.00" currency="USD" buySell="SELL" description="NVIDIA CORP"/>
                  </Trades>
                </FlexStatement>
              </FlexStatements>
            </FlexQueryResponse>
            XML;

        $trades = $this->importer->parse($xml);

        self::assertCount(2, $trades);

        $buy = $trades[0];
        self::assertSame('555', $buy->externalId);
        self::assertSame('NVDA', $buy->symbol);
        self::assertSame('US67066G1040', $buy->isin);
        self::assertSame(TransactionType::BUY, $buy->type);
        self::assertSame('2025-03-10', $buy->tradeDate->format('Y-m-d'));
        self::assertSame('10', $buy->quantity);
        self::assertSame('120.50', $buy->pricePerShare);
        self::assertSame('1', $buy->fee, 'commission magnitude');
        self::assertSame('USD', $buy->currency);

        $sell = $trades[1];
        self::assertSame(TransactionType::SELL, $sell->type);
        self::assertSame('4', $sell->quantity, 'absolute quantity');
    }

    public function testInvalidXmlThrows(): void
    {
        $this->expectException(ImportException::class);
        $this->importer->parse('not xml <<<');
    }
}
