<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Small helpers shared by the CSV importers: tolerant header lookup, delimiter
 * detection, date parsing and symbol derivation.
 */
trait CsvColumnTrait
{
    private function detectDelimiter(string $content): string
    {
        $firstLine = strtok($content, "\n") ?: '';

        return substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
    }

    /**
     * @param array<string, string|null> $row
     *
     * @return array<string, string|null>
     */
    private function normalizeKeys(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            $clean = strtolower(trim(str_replace("\u{FEFF}", '', (string) $key)));
            $normalized[$clean] = $value;
        }

        return $normalized;
    }

    /**
     * Returns the first non-empty value among the candidate (lowercased) headers.
     *
     * @param array<string, string|null> $row
     * @param list<string>               $candidates
     */
    private function col(array $row, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (isset($row[$candidate]) && '' !== trim((string) $row[$candidate])) {
                return trim((string) $row[$candidate]);
            }
        }

        return null;
    }

    private function parseDate(string $value): \DateTimeImmutable
    {
        $value = trim($value);
        foreach (['!Y-m-d', '!d-m-Y', '!d/m/Y', '!m/d/Y', '!Ymd'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $value);
            if (false !== $date) {
                return $date;
            }
        }

        throw new ImportException(sprintf('Unrecognised date "%s".', $value));
    }

    private function deriveSymbol(?string $symbol, ?string $isin, string $name): string
    {
        if (null !== $symbol && '' !== trim($symbol)) {
            return strtoupper(substr(trim($symbol), 0, 32));
        }

        $fromName = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name) ?? '');
        if ('' !== $fromName) {
            return substr($fromName, 0, 12);
        }

        return $isin ?? 'UNKNOWN';
    }
}
