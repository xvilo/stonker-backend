<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Account;
use App\Entity\AccountMembership;
use App\Entity\BrokerConnection;
use App\Entity\User;
use App\Enum\BrokerType;
use App\Enum\MembershipRole;
use App\Repository\AccountRepository;
use App\Service\CredentialEncryption;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Authorization for the manual broker-sync trigger endpoint. The connections
 * used here have no real IBKR credentials, so every attempt fails fast with
 * "missing token or queryId" (no network call) — these tests only exercise
 * who is allowed to hit the endpoint and the response shape, not the fetch
 * itself (see BrokerSyncServiceRetryWindowTest / IbkrFlexImporterTest for that).
 */
final class BrokerConnectionSyncTest extends ApiTestCase
{
    public function testOwnerCanTriggerManualSync(): void
    {
        $client = static::createClient();
        $token = $this->token($client, 'sem.schilder@team.blue');
        $connectionId = $this->createConnection('Personal', BrokerType::IBKR);

        $response = $client->request('POST', "/api/broker_connections/{$connectionId}/sync", ['auth_bearer' => $token]);

        self::assertResponseStatusCodeSame(201);
        $data = $response->toArray();
        self::assertSame('MANUAL', $data['trigger']);
        self::assertFalse($data['fetched']);
        self::assertSame('missing token or queryId', $data['note']);
    }

    public function testEditorCanTriggerManualSync(): void
    {
        $client = static::createClient();
        $token = $this->token($client, 'partner@example.com');
        $connectionId = $this->createConnection('Joint', BrokerType::IBKR);

        $client->request('POST', "/api/broker_connections/{$connectionId}/sync", ['auth_bearer' => $token]);

        self::assertResponseStatusCodeSame(201);
    }

    public function testViewerCannotTriggerManualSync(): void
    {
        $client = static::createClient();
        $viewerToken = $this->token($client, $this->registerViewerOn('Personal'));
        $connectionId = $this->createConnection('Personal', BrokerType::IBKR);

        $client->request('POST', "/api/broker_connections/{$connectionId}/sync", ['auth_bearer' => $viewerToken]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testUserWithNoMembershipGets404(): void
    {
        $client = static::createClient();
        $connectionId = $this->createConnection('Personal', BrokerType::IBKR);

        $client->request('POST', '/api/register', [
            'json' => ['email' => 'outsider@example.com', 'name' => 'Out Sider', 'plainPassword' => 'supersecret'],
        ]);
        $outsiderToken = $this->token($client, 'outsider@example.com', 'supersecret');

        $client->request('POST', "/api/broker_connections/{$connectionId}/sync", ['auth_bearer' => $outsiderToken]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testDegiroConnectionReturns400(): void
    {
        $client = static::createClient();
        $token = $this->token($client, 'sem.schilder@team.blue');
        $connectionId = $this->createConnection('Personal', BrokerType::DEGIRO);

        $client->request('POST', "/api/broker_connections/{$connectionId}/sync", ['auth_bearer' => $token]);

        self::assertResponseStatusCodeSame(400);
    }

    /** Persists a broker connection with no usable credentials on the named seeded account; returns its id. */
    private function createConnection(string $accountName, BrokerType $broker): string
    {
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var AccountRepository $accounts */
        $accounts = $container->get(AccountRepository::class);
        /** @var CredentialEncryption $encryption */
        $encryption = $container->get(CredentialEncryption::class);

        $account = $accounts->findOneBy(['name' => $accountName]);
        self::assertInstanceOf(Account::class, $account);

        $connection = new BrokerConnection($account, $broker, 'Test connection', $encryption->encrypt([]));
        $em->persist($connection);
        $em->flush();

        return (string) $connection->getId();
    }

    /** Registers a fresh user, grants them VIEWER on the named seeded account, and returns their email. */
    private function registerViewerOn(string $accountName): string
    {
        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);
        /** @var AccountRepository $accounts */
        $accounts = $container->get(AccountRepository::class);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $account = $accounts->findOneBy(['name' => $accountName]);
        self::assertInstanceOf(Account::class, $account);

        $viewer = new User('viewer@example.com', 'View Only');
        $viewer->setPassword($hasher->hashPassword($viewer, 'password'));
        $em->persist($viewer);
        $em->persist(new AccountMembership($account, $viewer, MembershipRole::VIEWER));
        $em->flush();

        return $viewer->getEmail();
    }
}
