<?php

declare(strict_types=1);

namespace App\Tests\Cleanup;

use App\Cleanup\ForexInstrumentCleaner;
use App\Entity\Instrument;
use App\Entity\Transaction;
use App\Enum\InstrumentType;
use App\Repository\InstrumentRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ForexInstrumentCleanerTest extends TestCase
{
    public function testReportsNothingWhenNoForexInstruments(): void
    {
        $instruments = $this->createStub(InstrumentRepository::class);
        $instruments->method('findCurrencyPairs')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('remove');
        $em->expects(self::never())->method('flush');

        $cleaner = new ForexInstrumentCleaner($instruments, $this->createStub(TransactionRepository::class), $em);

        $report = $cleaner->clean(true);

        self::assertTrue($report->isEmpty());
        self::assertSame('forex', $cleaner->key());
    }

    public function testDryRunReportsButDoesNotDelete(): void
    {
        $eurusd = new Instrument('EUR.USD', 'EUR.USD', InstrumentType::STOCK, 'USD');

        $instruments = $this->createStub(InstrumentRepository::class);
        $instruments->method('findCurrencyPairs')->willReturn([$eurusd]);

        $transactions = $this->createStub(TransactionRepository::class);
        $transactions->method('findBy')->willReturn([$this->createStub(Transaction::class)]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('remove');
        $em->expects(self::never())->method('flush');

        $report = (new ForexInstrumentCleaner($instruments, $transactions, $em))->clean(false);

        self::assertFalse($report->isEmpty());
        self::assertSame(['Symbol', 'Name', 'Transactions'], $report->headers);
        self::assertSame([['EUR.USD', 'EUR.USD', 1]], $report->rows);
        self::assertSame('1 instrument(s) and 1 transaction(s)', $report->summary);
    }

    public function testApplyRemovesTransactionsThenInstrumentAndFlushes(): void
    {
        $eurusd = new Instrument('EUR.USD', 'EUR.USD', InstrumentType::STOCK, 'USD');
        $txn = $this->createStub(Transaction::class);

        $instruments = $this->createStub(InstrumentRepository::class);
        $instruments->method('findCurrencyPairs')->willReturn([$eurusd]);

        $transactions = $this->createStub(TransactionRepository::class);
        $transactions->method('findBy')->willReturn([$txn]);

        $removed = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('remove')->willReturnCallback(function (object $entity) use (&$removed): void {
            $removed[] = $entity;
        });
        $em->expects(self::once())->method('flush');

        $report = (new ForexInstrumentCleaner($instruments, $transactions, $em))->clean(true);

        // Transaction must be removed before the instrument it references.
        self::assertSame([$txn, $eurusd], $removed);
        self::assertSame('1 instrument(s) and 1 transaction(s)', $report->summary);
    }
}
