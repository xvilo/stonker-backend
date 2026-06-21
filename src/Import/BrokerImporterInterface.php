<?php

declare(strict_types=1);

namespace App\Import;

use App\Enum\BrokerType;
use App\Enum\TransactionSource;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Parses a broker export (CSV or Flex XML) into broker-agnostic trades. The
 * ImportService selects the right importer by (broker, source) and handles
 * instrument resolution, dedupe and persistence.
 */
#[AutoconfigureTag('app.broker_importer')]
interface BrokerImporterInterface
{
    public function getBroker(): BrokerType;

    public function getSource(): TransactionSource;

    /**
     * @return list<ParsedTrade>
     *
     * @throws ImportException on malformed input
     */
    public function parse(string $content): array;
}
