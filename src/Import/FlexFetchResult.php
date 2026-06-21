<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Outcome of an IBKR Flex fetch. On success `statement` holds the XML; on
 * failure `errorCode`/`errorMessage` carry IBKR's reason (e.g. 1001 throttle,
 * 1019 still-generating, or a transport error) for diagnostics.
 */
final readonly class FlexFetchResult
{
    public function __construct(
        public ?string $statement = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {
    }

    public function isSuccess(): bool
    {
        return null !== $this->statement;
    }

    public function error(): ?string
    {
        if ($this->isSuccess()) {
            return null;
        }

        return trim(sprintf('%s %s', $this->errorCode ?? '', $this->errorMessage ?? '')) ?: 'unknown error';
    }
}
