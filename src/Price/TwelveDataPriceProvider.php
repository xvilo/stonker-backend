<?php

declare(strict_types=1);

namespace App\Price;

use App\Entity\Instrument;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reference adapter for the Twelve Data free tier (https://twelvedata.com).
 *
 * Disabled unless TWELVEDATA_API_KEY is set, so the app never makes external
 * calls by default. Free-tier coverage of EU ETFs/ISINs is patchy and rate
 * limited — hence the manual-snapshot fallback is load-bearing.
 *
 * Lower priority than Yahoo: it only runs for instruments Yahoo couldn't price.
 */
#[AsTaggedItem(priority: 10)]
final class TwelveDataPriceProvider implements PriceProviderInterface
{
    private const ENDPOINT = 'https://api.twelvedata.com/quote';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(service: 'limiter.twelve_data')]
        private readonly RateLimiterFactory $rateLimiter,
        #[Autowire(env: 'TWELVEDATA_API_KEY')]
        private readonly string $apiKey = '',
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function getName(): string
    {
        return 'twelvedata';
    }

    public function supports(Instrument $instrument): bool
    {
        return '' !== $this->apiKey;
    }

    public function fetchLatest(Instrument $instrument): ?PriceQuote
    {
        // Block until a credit is available so we never exceed the free-tier
        // 8 requests/minute (otherwise IBKR-style 429s).
        $this->rateLimiter->create('twelvedata')->reserve(1)->wait();

        try {
            $response = $this->httpClient->request('GET', self::ENDPOINT, [
                'query' => [
                    'symbol' => $instrument->getSymbol(),
                    'apikey' => $this->apiKey,
                ],
                'timeout' => 10,
            ]);

            if (429 === $response->getStatusCode()) {
                $this->logger?->warning('Twelve Data rate limit hit (429) despite throttling', ['symbol' => $instrument->getSymbol()]);

                return null;
            }

            $data = $response->toArray(false);

            // Twelve Data signals errors with {"status":"error","message":...}.
            if (($data['status'] ?? null) === 'error' || !isset($data['close'])) {
                $this->logger?->info('Twelve Data returned no price', [
                    'symbol' => $instrument->getSymbol(),
                    'message' => $data['message'] ?? null,
                ]);

                return null;
            }

            $date = isset($data['datetime'])
                ? (\DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) $data['datetime'], 0, 10)) ?: new \DateTimeImmutable('today'))
                : new \DateTimeImmutable('today');

            return new PriceQuote(
                date: $date,
                close: (string) $data['close'],
                currency: isset($data['currency']) ? strtoupper((string) $data['currency']) : $instrument->getCurrency(),
            );
        } catch (\Throwable $e) {
            $this->logger?->warning('Twelve Data fetch failed', [
                'symbol' => $instrument->getSymbol(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
