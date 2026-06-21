<?php

declare(strict_types=1);

namespace App\Import;

use App\Enum\BrokerType;
use App\Enum\TransactionSource;
use App\Enum\TransactionType;
use League\Csv\Reader;

/**
 * Parses a DeGiro "Transactions" CSV export (incl. the Dutch format).
 *
 * DeGiro has no official API, so CSV is the integration path — and its export is
 * awkward: duplicate empty header columns (so header mode can't be used),
 * decimal commas, an unsigned quantity (direction comes from the sign of the
 * trade value, not the quantity), the currency in the unnamed column right after
 * the price, and an Order ID that can be shifted a column. We therefore read
 * positionally with a name→index map and find the Order ID by UUID match.
 */
final class DegiroCsvImporter implements BrokerImporterInterface
{
    use CsvColumnTrait;

    public function getBroker(): BrokerType
    {
        return BrokerType::DEGIRO;
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
            // No header offset: DeGiro repeats empty header names, which header mode rejects.
            $rows = iterator_to_array($reader->getRecords(), false);
        } catch (\Throwable $e) {
            throw new ImportException('Could not read DeGiro CSV: '.$e->getMessage(), 0, $e);
        }

        if (\count($rows) < 2) {
            return [];
        }

        $header = array_map(static fn ($h): string => strtolower(trim((string) $h)), array_shift($rows));
        $indexOf = static function (array $candidates) use ($header): ?int {
            foreach ($header as $i => $name) {
                if (\in_array($name, $candidates, true)) {
                    return $i;
                }
            }

            return null;
        };

        $dateI = $indexOf(['datum', 'date']);
        $productI = $indexOf(['product', 'name']);
        $isinI = $indexOf(['isin']);
        $qtyI = $indexOf(['aantal', 'quantity']);
        $priceI = $indexOf(['koers', 'price']);
        $currencyI = null !== $priceI ? $priceI + 1 : null; // unnamed column after the price holds its currency
        $valueI = $indexOf(['lokale waarde', 'local value', 'waarde', 'value', 'totaal eur', 'totaal', 'total']);

        // Any "kosten"/cost column is a fee (AutoFX + transaction costs).
        $feeIndexes = [];
        foreach ($header as $i => $name) {
            if (str_contains($name, 'kosten') || str_starts_with($name, 'transaction costs') || \in_array($name, ['fee', 'costs'], true)) {
                $feeIndexes[] = $i;
            }
        }

        $cell = static fn (array $row, ?int $i): string => (null !== $i && \array_key_exists($i, $row)) ? trim((string) $row[$i]) : '';

        $trades = [];
        foreach ($rows as $row) {
            $qtyRaw = $cell($row, $qtyI);
            $priceRaw = $cell($row, $priceI);
            if ('' === $qtyRaw || '' === $priceRaw) {
                continue; // not a trade row
            }

            $quantity = $this->decimal($qtyRaw);
            if (0.0 === (float) $quantity) {
                continue;
            }

            $value = $this->decimal($cell($row, $valueI));
            // Negative trade value = cash out = BUY; positive = SELL. Fall back to a signed quantity.
            $type = match (true) {
                '' !== $value && (float) $value < 0 => TransactionType::BUY,
                '' !== $value && (float) $value > 0 => TransactionType::SELL,
                default => str_starts_with($qtyRaw, '-') ? TransactionType::SELL : TransactionType::BUY,
            };

            $currency = strtoupper($cell($row, $currencyI));
            if (1 !== preg_match('/^[A-Z]{3}$/', $currency)) {
                $currency = 'EUR';
            }

            $fee = 0.0;
            foreach ($feeIndexes as $fi) {
                $fee += abs((float) $this->decimal($cell($row, $fi)));
            }

            $isin = strtoupper($cell($row, $isinI)) ?: null;
            if (null !== $isin && 1 !== preg_match('/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/', $isin)) {
                $isin = null;
            }
            $name = $cell($row, $productI) ?: ($isin ?? 'Unknown');
            $date = $this->parseDate($cell($row, $dateI));

            $externalId = $this->findUuid($row) ?? sprintf('%s|%s|%s|%s', $date->format('Y-m-d'), $isin ?? $name, $quantity, $priceRaw);

            $trades[] = new ParsedTrade(
                externalId: $externalId,
                isin: $isin,
                symbol: $this->deriveSymbol(null, $isin, $name),
                name: $name,
                type: $type,
                tradeDate: $date,
                quantity: ltrim($quantity, '-'),
                pricePerShare: $this->decimal($priceRaw),
                currency: $currency,
                fee: $this->formatMoney($fee),
                feeCurrency: $currency,
            );
        }

        return $trades;
    }

    /** Normalise a DeGiro number ("1.234,56" / "126,8000" / "-1,00") to a plain decimal string. */
    private function decimal(string $value): string
    {
        $value = trim($value);
        if ('' === $value) {
            return '';
        }
        $value = str_replace(['.', ' ', "\u{a0}"], '', $value); // strip thousands separators + (nbsp) spaces
        $value = str_replace(',', '.', $value);                  // decimal comma -> point

        return $value;
    }

    private function formatMoney(float $amount): string
    {
        return rtrim(rtrim(sprintf('%.4f', $amount), '0'), '.') ?: '0';
    }

    /** @param array<int, string> $row */
    private function findUuid(array $row): ?string
    {
        foreach ($row as $field) {
            if (1 === preg_match('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', (string) $field, $m)) {
                return $m[0];
            }
        }

        return null;
    }
}
