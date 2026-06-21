<?php

declare(strict_types=1);

namespace App\Market;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OpenFIGI (https://www.openfigi.com/api) — Bloomberg's free, official mapping
 * service. Resolves ISINs to candidate tickers + exchange codes. Works from
 * servers (unlike the unofficial Yahoo endpoints), so it's reliable for cleaning
 * up the name-derived symbols broker imports leave behind.
 */
final class OpenFigiClient
{
    private const URL = 'https://api.openfigi.com/v3/mapping';
    private const BATCH = 10; // max jobs per request without an API key

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire(service: 'limiter.openfigi')]
        private readonly RateLimiterFactory $rateLimiter,
        #[Autowire(env: 'OPENFIGI_API_KEY')]
        private readonly string $apiKey = '',
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param list<string> $isins
     *
     * @return array<string, list<array{ticker?: string, exchCode?: string, name?: string, securityType?: string, marketSector?: string}>>
     *                                  ISIN => candidate listings (empty list if none found)
     */
    public function map(array $isins): array
    {
        $isins = array_values(array_unique(array_filter($isins)));
        $result = [];

        foreach (array_chunk($isins, self::BATCH) as $chunk) {
            $this->rateLimiter->create('openfigi')->reserve(1)->wait();

            $headers = ['Content-Type' => 'application/json'];
            if ('' !== $this->apiKey) {
                $headers['X-OPENFIGI-APIKEY'] = $this->apiKey;
            }

            try {
                $rows = $this->httpClient->request('POST', self::URL, [
                    'headers' => $headers,
                    'json' => array_map(static fn (string $isin): array => ['idType' => 'ID_ISIN', 'idValue' => $isin], $chunk),
                    'timeout' => 15,
                ])->toArray(false);
            } catch (\Throwable $e) {
                $this->logger?->warning('OpenFIGI mapping failed', ['error' => $e->getMessage()]);
                foreach ($chunk as $isin) {
                    $result[$isin] = [];
                }

                continue;
            }

            // Responses are positionally aligned with the request jobs.
            foreach ($chunk as $i => $isin) {
                $result[$isin] = $rows[$i]['data'] ?? [];
            }
        }

        return $result;
    }
}
