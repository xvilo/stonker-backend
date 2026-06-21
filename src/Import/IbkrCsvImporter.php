<?php

declare(strict_types=1);

namespace App\Import;

use App\Enum\BrokerType;
use App\Enum\TransactionSource;
use App\Enum\TransactionType;
use League\Csv\Reader;

/**
 * Parses an IBKR Flex/Activity "Trades" CSV export. Uses TradeID as the dedupe
 * key, the Buy/Sell column (falling back to the sign of Quantity) for the
 * direction, and IBCommission as the fee.
 */
final class IbkrCsvImporter implements BrokerImporterInterface
{
    use CsvColumnTrait;

    public function getBroker(): BrokerType
    {
        return BrokerType::IBKR;
    }

    public function getSource(): TransactionSource
    {
        return TransactionSource::CSV;
    }

    public function parse(string $content): array
    {
        try {
            $reader = Reader::fromString($content);
            $reader->setDelimiter($this->detectDelimiter($content));
            $reader->setHeaderOffset(0);
            $records = $reader->getRecords();
        } catch (\Throwable $e) {
            throw new ImportException('Could not read IBKR CSV: '.$e->getMessage(), 0, $e);
        }

        $trades = [];
        foreach ($records as $row) {
            $row = $this->normalizeKeys($row);

            $symbol = $this->col($row, ['symbol']);
            $quantityRaw = $this->col($row, ['quantity']);
            $price = $this->col($row, ['tradeprice', 'price', 't. price']);
            if (null === $symbol || null === $quantityRaw || null === $price) {
                continue;
            }

            $quantity = (float) str_replace(',', '', $quantityRaw);
            if (0.0 === $quantity) {
                continue;
            }

            $buySell = strtoupper((string) ($this->col($row, ['buy/sell', 'buysell']) ?? ''));
            $type = match (true) {
                str_starts_with($buySell, 'B') => TransactionType::BUY,
                str_starts_with($buySell, 'S') => TransactionType::SELL,
                default => $quantity > 0 ? TransactionType::BUY : TransactionType::SELL,
            };

            $currency = strtoupper($this->col($row, ['currencyprimary', 'currency']) ?? 'USD');
            $fee = (string) abs((float) str_replace(',', '', (string) ($this->col($row, ['ibcommission', 'commission', 'comm/fee']) ?? '0')));
            $isin = $this->col($row, ['isin']);
            $name = $this->col($row, ['description', 'name']) ?? $symbol;
            $externalId = $this->col($row, ['tradeid', 'transactionid', 'id'])
                ?? sprintf('%s|%s|%s|%s', $this->col($row, ['tradedate', 'date']) ?? '', $symbol, $quantityRaw, $price);

            $trades[] = new ParsedTrade(
                externalId: $externalId,
                isin: $isin,
                symbol: strtoupper($symbol),
                name: $name,
                type: $type,
                tradeDate: $this->parseDate($this->col($row, ['tradedate', 'date']) ?? ''),
                quantity: (string) abs($quantity),
                pricePerShare: str_replace(',', '', (string) $price),
                currency: $currency,
                fee: $fee,
                feeCurrency: $currency,
            );
        }

        return $trades;
    }
}
