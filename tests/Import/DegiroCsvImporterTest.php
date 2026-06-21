<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Enum\TransactionType;
use App\Import\DegiroCsvImporter;
use PHPUnit\Framework\TestCase;

final class DegiroCsvImporterTest extends TestCase
{
    private function csv(): string
    {
        // Real Dutch DeGiro "Transactions" header (note the duplicate empty columns)
        // + one buy (negative value) and one sell (positive value, USD).
        return <<<CSV
            Datum,Tijd,Product,ISIN,Beurs,Uitvoeringsplaats,Aantal,Koers,,Lokale waarde,,Waarde EUR,Wisselkoers,AutoFX Kosten,Transactiekosten en/of kosten van derden EUR,Totaal EUR,Order ID,
            27-05-2025,09:04,VANGUARD FTSE ALL-WORLD UCITS ETF USD DIS,IE00B3RBWM25,EAM,XAMS,1,"126,8000",EUR,"-126,80",EUR,"-126,80",,"0,00","-1,00","-127,80",,36affd28-3583-4325-afe4-5816a4e986ef
            10-06-2025,10:00,SOME STOCK,US0000000000,NDQ,XNAS,2,"50,0000",USD,"100,00",USD,"100,00",,"0,00","-0,50","99,50",,aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee
            CSV;
    }

    public function testParsesDutchExportWithCommasAndValueBasedDirection(): void
    {
        $trades = (new DegiroCsvImporter())->parse($this->csv());

        self::assertCount(2, $trades);

        $buy = $trades[0];
        self::assertSame(TransactionType::BUY, $buy->type, 'negative value = cash out = buy');
        self::assertSame('1', $buy->quantity);
        self::assertSame('126.8000', $buy->pricePerShare, 'decimal comma normalised');
        self::assertSame('EUR', $buy->currency);
        self::assertSame('1', $buy->fee, 'AutoFX 0 + transaction cost 1.00');
        self::assertSame('IE00B3RBWM25', $buy->isin);
        self::assertSame('2025-05-27', $buy->tradeDate->format('Y-m-d'));
        self::assertSame('36affd28-3583-4325-afe4-5816a4e986ef', $buy->externalId, 'order id used as dedupe key');

        $sell = $trades[1];
        self::assertSame(TransactionType::SELL, $sell->type, 'positive value = cash in = sell');
        self::assertSame('2', $sell->quantity);
        self::assertSame('USD', $sell->currency, 'currency read from the column after price');
        self::assertSame('0.5', $sell->fee);
    }

    public function testEmptyFileYieldsNoTrades(): void
    {
        self::assertSame([], (new DegiroCsvImporter())->parse("Datum,Product\n"));
    }
}
