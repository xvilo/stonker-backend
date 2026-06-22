<?php

declare(strict_types=1);

namespace App\Cleanup;

/**
 * What a {@see DataCleanerInterface} found (and, when applied, removed) in a
 * single run. The command renders {@see $headers}/{@see $rows} as a table and
 * prints {@see $summary} as the one-line outcome.
 */
final class CleanupReport
{
    /**
     * @param list<string>                       $headers table column headers
     * @param list<list<int|string>>             $rows    one row per affected item, aligned to $headers
     * @param string                             $summary noun phrase describing what was found, e.g. "2 instrument(s) and 5 transaction(s)"
     */
    public function __construct(
        public readonly array $headers,
        public readonly array $rows,
        public readonly string $summary,
    ) {
    }

    public static function nothing(): self
    {
        return new self([], [], 'nothing');
    }

    public function isEmpty(): bool
    {
        return [] === $this->rows;
    }
}
