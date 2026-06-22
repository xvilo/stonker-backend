<?php

declare(strict_types=1);

namespace App\Import;

use App\Enum\BrokerType;
use App\Enum\TransactionSource;
use App\Enum\TransactionType;

/**
 * Parses an IBKR Flex Query statement (XML) into trades. Fed by IbkrFlexClient,
 * which fetches the statement using the account's stored Flex token + query id.
 */
final class IbkrFlexImporter implements BrokerImporterInterface
{
    use CsvColumnTrait;

    public function getBroker(): BrokerType
    {
        return BrokerType::IBKR;
    }

    public function getSource(): TransactionSource
    {
        return TransactionSource::FLEX;
    }

    public function parse(string $content): array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        libxml_use_internal_errors($previous);

        if (false === $xml) {
            throw new ImportException('Could not parse IBKR Flex XML statement.');
        }

        $tradeNodes = $xml->xpath('//Trade') ?: [];
        $trades = [];
        foreach ($tradeNodes as $node) {
            $attr = static fn (string $name): ?string => isset($node[$name]) ? (string) $node[$name] : null;

            $symbol = $attr('symbol');
            $quantityRaw = $attr('quantity');
            $price = $attr('tradePrice');
            if (null === $symbol || null === $quantityRaw || null === $price) {
                continue;
            }

            if (!$this->isEquityTrade($attr('assetCategory'), $symbol)) {
                continue; // forex (EUR.USD), options, futures, etc. — not tracked.
            }

            $quantity = (float) $quantityRaw;
            if (0.0 === $quantity) {
                continue;
            }

            $buySell = strtoupper((string) ($attr('buySell') ?? ''));
            $type = match (true) {
                str_starts_with($buySell, 'B') => TransactionType::BUY,
                str_starts_with($buySell, 'S') => TransactionType::SELL,
                default => $quantity > 0 ? TransactionType::BUY : TransactionType::SELL,
            };

            $currency = strtoupper($attr('currency') ?? 'USD');

            $trades[] = new ParsedTrade(
                externalId: $attr('tradeID') ?? sprintf('%s|%s|%s', $attr('tradeDate') ?? '', $symbol, $quantityRaw),
                isin: $attr('isin'),
                symbol: strtoupper($symbol),
                name: $attr('description') ?? $symbol,
                type: $type,
                tradeDate: $this->parseDate($attr('tradeDate') ?? ''),
                quantity: (string) abs($quantity),
                pricePerShare: $price,
                currency: $currency,
                fee: (string) abs((float) ($attr('ibCommission') ?? '0')),
                feeCurrency: strtoupper($attr('ibCommissionCurrency') ?? $currency),
            );
        }

        return $trades;
    }
}
