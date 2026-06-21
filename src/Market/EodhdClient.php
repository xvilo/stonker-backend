<?php

declare(strict_types=1);

namespace App\Market;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Low-level EODHD (https://eodhd.com) client shared by the price provider (latest
 * close) and the backfiller (full daily history). Every outbound call draws from
 * one rolling 20/day budget (free tier) via the shared limiter; over budget,
 * calls return empty so callers degrade gracefully.
 *
 * The ISIN→symbol resolution (search) is cached so it isn't repeated per run.
 */
final class EodhdClient
{
    private const SEARCH_URL = 'https://eodhd.com/api/search/';
    private const EOD_URL = 'https://eodhd.com/api/eod/';

    /** Preferred exchange codes per currency when an ISIN has several listings. */
    private const PREFERENCE = [
        'EUR' => ['AS', 'XETRA', 'PA', 'BR', 'MI', 'MC', 'LS', 'VI', 'IR', 'F'],
        'USD' => ['US'],
        'GBP' => ['LSE'],
        'CHF' => ['SW'],
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(service: 'limiter.eodhd')]
        private readonly RateLimiterFactory $rateLimiter,
        private readonly CacheItemPoolInterface $cache,
        #[Autowire(env: 'EODHD_API_KEY')]
        private readonly string $apiKey = '',
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== $this->apiKey;
    }

    /**
     * Best listing for an ISIN (with previousClose), preferring the instrument's
     * currency/exchange. Caches the resolved "CODE.EXCHANGE" symbol.
     *
     * @return array<string, mixed>|null
     */
    public function searchBest(string $isin, string $currency): ?array
    {
        if (!$this->isEnabled() || !$this->allow()) {
            return null;
        }

        try {
            $response = $this->httpClient->request('GET', self::SEARCH_URL.rawurlencode($isin), [
                'query' => ['api_token' => $this->apiKey, 'fmt' => 'json', 'limit' => 20],
                'timeout' => 15,
            ]);
            if (200 !== $response->getStatusCode()) {
                $this->logger?->warning('EODHD search failed', ['isin' => $isin, 'status' => $response->getStatusCode()]);

                return null;
            }
            $best = $this->pickBest($response->toArray(false), $currency);
        } catch (\Throwable $e) {
            $this->logger?->warning('EODHD search error', ['isin' => $isin, 'error' => $e->getMessage()]);

            return null;
        }

        if (null !== $best && isset($best['Code'], $best['Exchange'])) {
            $symbol = $best['Code'].'.'.$best['Exchange'];
            $this->cache->save($this->cache->getItem('eodhd_sym_'.$isin)->set($symbol)->expiresAfter(2_592_000));
        }

        return $best;
    }

    /**
     * Resolve (and cache) an ISIN to its EODHD "CODE.EXCHANGE" symbol.
     */
    public function resolveSymbol(string $isin, string $currency): ?string
    {
        $item = $this->cache->getItem('eodhd_sym_'.$isin);
        if ($item->isHit()) {
            return $item->get();
        }

        $best = $this->searchBest($isin, $currency);

        return (null !== $best && isset($best['Code'], $best['Exchange'])) ? $best['Code'].'.'.$best['Exchange'] : null;
    }

    /**
     * Daily OHLC bars for a symbol over a date range (one API call, any range).
     *
     * @return list<array{date: string, close: float|int|string}>
     */
    public function eod(string $symbol, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if (!$this->isEnabled() || !$this->allow()) {
            return [];
        }

        try {
            $response = $this->httpClient->request('GET', self::EOD_URL.rawurlencode($symbol), [
                'query' => [
                    'api_token' => $this->apiKey,
                    'fmt' => 'json',
                    'period' => 'd',
                    'from' => $from->format('Y-m-d'),
                    'to' => $to->format('Y-m-d'),
                ],
                'timeout' => 30,
            ]);
            if (200 !== $response->getStatusCode()) {
                $this->logger?->warning('EODHD eod failed', ['symbol' => $symbol, 'status' => $response->getStatusCode()]);

                return [];
            }

            return $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger?->warning('EODHD eod error', ['symbol' => $symbol, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /** Consume one unit of the shared 20/day budget; false when exhausted. */
    private function allow(): bool
    {
        return $this->rateLimiter->create('eodhd')->consume(1)->isAccepted();
    }

    /**
     * @param list<array<string, mixed>> $candidates
     *
     * @return array<string, mixed>|null
     */
    private function pickBest(array $candidates, string $currency): ?array
    {
        $priced = array_values(array_filter(
            $candidates,
            static fn ($c): bool => \is_array($c) && isset($c['previousClose']) && null !== $c['previousClose'],
        ));
        if ([] === $priced) {
            return null;
        }

        $currency = strtoupper($currency);
        $sameCurrency = array_values(array_filter(
            $priced,
            static fn ($c): bool => strtoupper((string) ($c['Currency'] ?? '')) === $currency,
        ));
        $pool = [] !== $sameCurrency ? $sameCurrency : $priced;

        foreach ($pool as $candidate) {
            if (true === ($candidate['isPrimary'] ?? false)) {
                return $candidate;
            }
        }
        foreach (self::PREFERENCE[$currency] ?? [] as $exchange) {
            foreach ($pool as $candidate) {
                if (strtoupper((string) ($candidate['Exchange'] ?? '')) === $exchange) {
                    return $candidate;
                }
            }
        }

        return $pool[0];
    }
}
