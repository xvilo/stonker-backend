<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Account;
use App\Entity\BrokerConnection;
use App\Enum\BrokerType;
use PHPUnit\Framework\TestCase;

final class BrokerConnectionRetryWindowTest extends TestCase
{
    private BrokerConnection $connection;

    protected function setUp(): void
    {
        $this->connection = new BrokerConnection(new Account('Test'), BrokerType::IBKR, 'Test IBKR');
    }

    public function testFailureOpensRetryWindowWhenNoneIsActive(): void
    {
        $now = new \DateTimeImmutable('2026-08-24 09:00:00');
        self::assertNull($this->connection->getRetryUntil());

        $this->connection->recordSyncOutcome(false, $now);

        self::assertEquals($now->modify('+6 hours'), $this->connection->getRetryUntil());
    }

    public function testFailureInsideAnActiveWindowDoesNotExtendIt(): void
    {
        $firstFailure = new \DateTimeImmutable('2026-08-24 09:00:00');
        $this->connection->recordSyncOutcome(false, $firstFailure);
        $originalDeadline = $this->connection->getRetryUntil();

        // A retry attempt 30 minutes later also fails — still well within the window.
        $this->connection->recordSyncOutcome(false, $firstFailure->modify('+30 minutes'));

        self::assertEquals($originalDeadline, $this->connection->getRetryUntil(), 'the deadline must not be pushed out by a second failure');
    }

    public function testFailureAfterALapsedWindowOpensAFreshOne(): void
    {
        $firstFailure = new \DateTimeImmutable('2026-08-24 09:00:00');
        $this->connection->recordSyncOutcome(false, $firstFailure);

        // Past the original 6h deadline — e.g. the worker was down and only the
        // next day's scheduled sync catches the failure.
        $muchLater = $firstFailure->modify('+2 days');
        $this->connection->recordSyncOutcome(false, $muchLater);

        self::assertEquals($muchLater->modify('+6 hours'), $this->connection->getRetryUntil());
    }

    public function testSuccessClearsAnOpenRetryWindow(): void
    {
        $now = new \DateTimeImmutable('2026-08-24 09:00:00');
        $this->connection->recordSyncOutcome(false, $now);
        self::assertNotNull($this->connection->getRetryUntil());

        $this->connection->recordSyncOutcome(true, $now->modify('+30 minutes'));

        self::assertNull($this->connection->getRetryUntil());
    }

    public function testSuccessWithNoPriorFailureLeavesWindowClosed(): void
    {
        $this->connection->recordSyncOutcome(true, new \DateTimeImmutable('2026-08-24 09:00:00'));

        self::assertNull($this->connection->getRetryUntil());
    }
}
