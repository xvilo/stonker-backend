<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class SecurityAndPnlTest extends ApiTestCase
{
    public function testUnauthenticatedAccessIsRejected(): void
    {
        static::createClient()->request('GET', '/api/accounts');

        self::assertResponseStatusCodeSame(401);
    }

    public function testMeReturnsUserWithMemberships(): void
    {
        $client = static::createClient();
        $token = $this->token($client, 'sem.schilder@team.blue');

        $me = $this->getJson($client, '/api/me', $token);

        self::assertSame('sem.schilder@team.blue', $me['email']);
        self::assertCount(2, $me['memberships'], 'Sem owns Personal and Joint');
    }

    public function testRegistrationCreatesIsolatedAccount(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/register', [
            'json' => ['email' => 'newbie@example.com', 'name' => 'New Bie', 'plainPassword' => 'supersecret'],
        ]);
        self::assertResponseStatusCodeSame(201);

        $token = $this->token($client, 'newbie@example.com', 'supersecret');
        $accounts = $this->getJson($client, '/api/accounts', $token);

        // The new user sees only their own auto-created account — not Sem's.
        self::assertCount(1, $accounts);
        self::assertStringContainsString('New Bie', $accounts[0]['name']);
    }

    public function testCrossTenantItemReadReturns404(): void
    {
        $client = static::createClient();

        $semToken = $this->token($client, 'sem.schilder@team.blue');
        $personalId = $this->personalAccountId($client, $semToken);

        $client->request('POST', '/api/register', [
            'json' => ['email' => 'outsider@example.com', 'name' => 'Out Sider', 'plainPassword' => 'supersecret'],
        ]);
        $outsiderToken = $this->token($client, 'outsider@example.com', 'supersecret');

        $client->request('GET', '/api/accounts/'.$personalId, ['auth_bearer' => $outsiderToken]);
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/api/accounts/'.$personalId.'/positions', ['auth_bearer' => $outsiderToken]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testPerCurrencyPnlMatchesSeededPortfolio(): void
    {
        $client = static::createClient();
        $token = $this->token($client, 'sem.schilder@team.blue');
        $personalId = $this->personalAccountId($client, $token);

        $pnl = $this->getJson($client, "/api/accounts/{$personalId}/pnl", $token);
        $byCurrency = [];
        foreach ($pnl['buckets'] as $bucket) {
            $byCurrency[$bucket['currency']] = $bucket;
        }

        self::assertArrayHasKey('EUR', $byCurrency);
        self::assertArrayHasKey('USD', $byCurrency);

        // EUR: VWCE (realized 116.80) + ASML; USD: AAPL (realized 148.75) + MSFT.
        self::assertSame('116.8000', $byCurrency['EUR']['realizedPnl']);
        self::assertSame('520.2000', $byCurrency['EUR']['unrealizedPnl']);
        self::assertSame('148.7500', $byCurrency['USD']['realizedPnl']);
        self::assertSame('1003.2500', $byCurrency['USD']['unrealizedPnl']);
    }

    private function personalAccountId(object $client, string $token): string
    {
        foreach ($this->getJson($client, '/api/accounts', $token) as $account) {
            if ('Personal' === $account['name']) {
                return $account['id'];
            }
        }
        self::fail('Personal account not found.');
    }
}
