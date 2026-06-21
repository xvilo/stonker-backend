<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PerformanceReport;
use App\Service\AccountAccess;
use App\Service\PortfolioValuationService;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * @implements ProviderInterface<PerformanceReport>
 */
final class PerformanceProvider implements ProviderInterface
{
    public function __construct(
        private readonly AccountAccess $accountAccess,
        private readonly PortfolioValuationService $valuation,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PerformanceReport
    {
        $account = $this->accountAccess->getViewable((string) ($uriVariables['accountId'] ?? ''));

        $request = $this->requestStack->getCurrentRequest();
        $to = $this->parseDate($request?->query->get('to')) ?? new \DateTimeImmutable('today');
        $from = $this->parseDate($request?->query->get('from')) ?? $to->modify('-2 years');
        $currency = $request?->query->get('currency');

        $series = $this->valuation->buildSeries($account, $from, $to);
        if (\is_string($currency) && '' !== $currency) {
            $currency = strtoupper($currency);
            $series = array_values(array_filter($series, static fn ($s): bool => $s->currency === $currency));
        }

        return new PerformanceReport(
            accountId: $account->getId()->toRfc4122(),
            from: $from->format('Y-m-d'),
            to: $to->format('Y-m-d'),
            series: $series,
        );
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || '' === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return false !== $date ? $date : null;
    }
}
