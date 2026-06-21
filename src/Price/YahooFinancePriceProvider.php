<?php

declare(strict_types=1);

namespace App\Price;

use App\Entity\Instrument;
use App\Market\YahooSession;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Free (unofficial) Yahoo Finance provider. Resolves an instrument's ISIN to a
 * Yahoo symbol via the search endpoint, then reads the quote from the chart
 * endpoint — both work without an API key. Broad EU + US + ETF coverage, which
 * is why it's the primary provider (Twelve Data is the fallback).
 *
 * Looking up by ISIN sidesteps the junk symbols from broker imports and pins
 * the correct listing (e.g. ASML on Amsterdam, not a US line). The ISIN→symbol
 * mapping is cached to avoid hammering the unofficial endpoints.
 */
#[AsTaggedItem(priority: 20)]
final class YahooFinancePriceProvider implements PriceProviderInterface
{
    private const SEARCH_URL = 'https://query1.finance.yahoo.com/v1/finance/search';
    private const CHART_URL = 'https://query1.finance.yahoo.com/v8/finance/chart/';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(service: 'limiter.yahoo_finance')]
        private readonly RateLimiterFactory $rateLimiter,
        private readonly CacheItemPoolInterface $cache,
        private readonly YahooSession $session,
        // Yahoo rejects requests without a current browser-like User-Agent (429).
        #[Autowire(env: 'YAHOO_USER_AGENT')]
        private readonly string $userAgent = '',
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Browser-like headers + the Yahoo session cookie — required to avoid 429.
     *
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = [
            'User-Agent' => $this->userAgent,
            'Accept' => 'application/json,text/plain,*/*',
            'Accept-Language' => 'en-US,en;q=0.9',
        ];
        if (null !== ($cookie = $this->session->cookie())) {
            $headers['Cookie'] = $cookie;
        }

        return $headers;
    }

    public function getName(): string
    {
        return 'yahoo';
    }

    public function supports(Instrument $instrument): bool
    {
        // Free and no key — worth trying for any instrument.
        return true;
    }

    public function fetchLatest(Instrument $instrument): ?PriceQuote
    {
        $symbol = $this->resolveSymbol($instrument);
        if (null === $symbol) {
            return null;
        }

        $this->throttle();
        try {
            $response = $this->httpClient->request('GET', self::CHART_URL.rawurlencode($symbol), [
                'headers' => $this->headers(),
                'timeout' => 10,
            ]);
            if (429 === $response->getStatusCode()) {
                $this->logger?->warning('Yahoo rate limited (429); skipping this run', ['symbol' => $symbol]);

                return null;
            }
            $meta = $response->toArray(false)['chart']['result'][0]['meta'] ?? null;
            if (!\is_array($meta) || !isset($meta['regularMarketPrice'])) {
                return null;
            }

            $timestamp = $meta['regularMarketTime'] ?? null;
            $date = \is_int($timestamp)
                ? (new \DateTimeImmutable('@'.$timestamp))->setTime(0, 0)
                : new \DateTimeImmutable('today');

            return new PriceQuote(
                date: $date,
                close: (string) $meta['regularMarketPrice'],
                currency: strtoupper((string) ($meta['currency'] ?? $instrument->getCurrency())),
            );
        } catch (\Throwable $e) {
            $this->logger?->warning('Yahoo chart fetch failed', ['symbol' => $symbol, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function resolveSymbol(Instrument $instrument): ?string
    {
        $isin = $instrument->getIsin();
        if (null === $isin) {
            return '' !== $instrument->getSymbol() ? $instrument->getSymbol() : null;
        }

        $item = $this->cache->getItem('yahoo_isin_'.$isin);
        if ($item->isHit()) {
            return $item->get();
        }

        $symbol = $this->searchByIsin($isin);
        if (null !== $symbol) {
            $this->cache->save($item->set($symbol)->expiresAfter(2_592_000)); // 30 days
        }

        return $symbol;
    }

    private function searchByIsin(string $isin): ?string
    {
        $this->throttle();
        try {
            $response = $this->httpClient->request('GET', self::SEARCH_URL, [
                'query' => ['q' => $isin, 'quotesCount' => 5, 'newsCount' => 0],
                'headers' => $this->headers(),
                'timeout' => 10,
            ]);
            if (429 === $response->getStatusCode()) {
                $this->logger?->warning('Yahoo rate limited (429) on ISIN search; skipping this run', ['isin' => $isin]);

                return null;
            }
            $quotes = $response->toArray(false)['quotes'] ?? [];
            foreach ($quotes as $quote) {
                if (!empty($quote['symbol']) && \in_array($quote['quoteType'] ?? '', ['EQUITY', 'ETF', 'MUTUALFUND'], true)) {
                    return (string) $quote['symbol'];
                }
            }
        } catch (\Throwable $e) {
            $this->logger?->warning('Yahoo ISIN search failed', ['isin' => $isin, 'error' => $e->getMessage()]);
        }

        return null;
    }

    private function throttle(): void
    {
        $this->rateLimiter->create('yahoo')->reserve(1)->wait();
    }
}
