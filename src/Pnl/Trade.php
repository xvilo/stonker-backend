<?php

declare(strict_types=1);

namespace App\Pnl;

use App\Enum\TransactionType;

/**
 * Minimal, persistence-free view of a transaction for P/L math. Decoupling the
 * calculator from the Doctrine entity keeps it trivially unit-testable.
 *
 * Amounts are exact decimal strings.
 */
final readonly class Trade
{
    public function __construct(
        public TransactionType $type,
        public string $quantity,
        public string $pricePerShare,
        public string $fee = '0',
    ) {
    }
}
