<?php

declare(strict_types=1);

namespace App\Market;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Supplies the Yahoo session cookie (A1/A3) that the unofficial endpoints now
 * require — without it they answer 429. A cookie is fetched once from
 * fc.yahoo.com and cached (it's valid ~1 year). A browser-copied cookie can be
 * pinned via YAHOO_COOKIE to bypass auto-fetch entirely (e.g. behind EU consent).
 */
final class YahooSession
{
    private const COOKIE_URL = 'https://fc.yahoo.com/';
    private const CACHE_KEY = 'yahoo_cookie';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheItemPoolInterface $cache,
        #[Autowire(env: 'YAHOO_USER_AGENT')]
        private readonly string $userAgent = '',
        #[Autowire(env: 'YAHOO_COOKIE')]
        private readonly string $manualCookie = '',
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function cookie(): ?string
    {
        if ('' !== $this->manualCookie) {
            return $this->manualCookie;
        }

        $item = $this->cache->getItem(self::CACHE_KEY);
        if ($item->isHit()) {
            return $item->get();
        }

        $cookie = $this->fetchCookie();
        if (null !== $cookie) {
            $this->cache->save($item->set($cookie)->expiresAfter(43_200)); // 12h
        }

        return $cookie;
    }

    private function fetchCookie(): ?string
    {
        try {
            $response = $this->httpClient->request('GET', self::COOKIE_URL, [
                'headers' => ['User-Agent' => $this->userAgent],
                'timeout' => 10,
            ]);
            $setCookies = $response->getHeaders(false)['set-cookie'] ?? [];

            $pairs = [];
            foreach ($setCookies as $setCookie) {
                $first = explode(';', $setCookie, 2)[0];
                if (str_contains($first, '=')) {
                    $name = explode('=', $first, 2)[0];
                    $pairs[$name] = $first;
                }
            }

            return [] !== $pairs ? implode('; ', $pairs) : null;
        } catch (\Throwable $e) {
            $this->logger?->warning('Yahoo cookie fetch failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
