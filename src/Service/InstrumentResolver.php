<?php

declare(strict_types=1);

namespace App\Service;

use App\Market\OpenFigiClient;
use App\Repository\InstrumentRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Backfills real tickers (and exchange codes) onto instruments by resolving
 * their ISIN through OpenFIGI. Fixes the name-derived symbols left by broker
 * imports (e.g. "VANGUARDFTSE" -> "VWRL") so the UI is correct and symbol-based
 * price lookups have a fighting chance.
 *
 * When an ISIN has listings on several exchanges, the one matching the
 * instrument's currency/region is preferred (Amsterdam/Xetra for EUR, US for USD).
 */
final class InstrumentResolver
{
    /** Bloomberg exchange-code preference per currency (best first). */
    private const PREFERENCE = [
        'EUR' => ['NA', 'GR', 'GY', 'FP', 'BB', 'IM', 'SM', 'VI'],
        'USD' => ['US', 'UW', 'UN', 'UQ', 'UP'],
        'GBP' => ['LN'],
        'GBX' => ['LN'],
        'CHF' => ['SW', 'SE'],
    ];

    public function __construct(
        private readonly OpenFigiClient $openFigi,
        private readonly InstrumentRepository $instruments,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return list<array{isin: string, name: string, oldSymbol: string, newSymbol: ?string, exchange: ?string, status: string}>
     */
    public function resolveAll(bool $apply): array
    {
        $instruments = $this->instruments->findWithIsin();
        if ([] === $instruments) {
            return [];
        }

        $map = $this->openFigi->map(array_map(static fn ($i): string => (string) $i->getIsin(), $instruments));

        $report = [];
        foreach ($instruments as $instrument) {
            $isin = (string) $instrument->getIsin();
            $oldSymbol = $instrument->getSymbol();
            $pick = $this->pickBest($map[$isin] ?? [], $instrument->getCurrency());

            if (null === $pick || empty($pick['ticker'])) {
                $report[] = $this->row($isin, $instrument->getName(), $oldSymbol, null, null, 'no match');

                continue;
            }

            $newSymbol = strtoupper((string) $pick['ticker']);
            $newExchange = isset($pick['exchCode']) ? strtoupper((string) $pick['exchCode']) : null;

            if ($newSymbol === $oldSymbol && $newExchange === $instrument->getExchange()) {
                $report[] = $this->row($isin, $instrument->getName(), $oldSymbol, $newSymbol, $newExchange, 'unchanged');

                continue;
            }

            if ($apply) {
                $instrument->setSymbol($newSymbol);
                if (null !== $newExchange) {
                    $instrument->setExchange($newExchange);
                }
            }
            $report[] = $this->row($isin, $instrument->getName(), $oldSymbol, $newSymbol, $newExchange, $apply ? 'updated' : 'would update');
        }

        if ($apply) {
            $this->em->flush();
        }

        return $report;
    }

    /**
     * @param list<array{ticker?: string, exchCode?: string}> $candidates
     */
    private function pickBest(array $candidates, string $currency): ?array
    {
        $candidates = array_values(array_filter($candidates, static fn ($c): bool => !empty($c['ticker'])));
        if ([] === $candidates) {
            return null;
        }

        foreach (self::PREFERENCE[strtoupper($currency)] ?? [] as $code) {
            foreach ($candidates as $candidate) {
                if (strtoupper((string) ($candidate['exchCode'] ?? '')) === $code) {
                    return $candidate;
                }
            }
        }

        return $candidates[0];
    }

    /**
     * @return array{isin: string, name: string, oldSymbol: string, newSymbol: ?string, exchange: ?string, status: string}
     */
    private function row(string $isin, string $name, string $oldSymbol, ?string $newSymbol, ?string $exchange, string $status): array
    {
        return [
            'isin' => $isin,
            'name' => $name,
            'oldSymbol' => $oldSymbol,
            'newSymbol' => $newSymbol,
            'exchange' => $exchange,
            'status' => $status,
        ];
    }
}
