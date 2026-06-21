<?php

declare(strict_types=1);

namespace App\Import;

use App\Enum\TransactionType;

/**
 * A broker-agnostic trade extracted from a CSV row or Flex statement entry.
 * The instrument is identified loosely (ISIN preferred, symbol fallback) and
 * resolved/created by the ImportService.
 */
final readonly class ParsedTrade
{
    public function __construct(
        public string $externalId,
        public ?string $isin,
        public string $symbol,
        public string $name,
        public TransactionType $type,
        public \DateTimeImmutable $tradeDate,
        public string $quantity,
        public string $pricePerShare,
        public string $currency,
        public string $fee = '0',
        public ?string $feeCurrency = null,
    ) {
    }
}
