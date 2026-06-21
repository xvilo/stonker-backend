<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase as BaseApiTestCase;
use App\DataFixtures\AppFixtures;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Loader;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Base for functional API tests: rebuilds the schema and loads the seed
 * portfolio into the test database before each test, so assertions can rely on
 * the known fixtures.
 */
abstract class ApiTestCase extends BaseApiTestCase
{
    protected function setUp(): void
    {
        // API Platform 5 will stop auto-booting the kernel on createClient();
        // opt in explicitly to keep current behaviour and silence the notice.
        static::$alwaysBootKernel = true;

        parent::setUp();

        $container = static::getContainer();
        /** @var EntityManagerInterface $em */
        $em = $container->get(EntityManagerInterface::class);

        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $loader = new Loader();
        $loader->addFixture(new AppFixtures($hasher));
        (new ORMExecutor($em, new ORMPurger()))->execute($loader->getFixtures());
    }

    /**
     * Logs in and returns the JWT access token.
     */
    protected function token(object $client, string $email, string $password = 'password'): string
    {
        $response = $client->request('POST', '/api/login_check', [
            'json' => ['email' => $email, 'password' => $password],
        ]);

        return $response->toArray()['token'];
    }

    /**
     * GET as JSON (not JSON-LD, so collections are plain arrays).
     *
     * @return array<mixed>
     */
    protected function getJson(object $client, string $url, string $token): array
    {
        return $client->request('GET', $url, [
            'auth_bearer' => $token,
            'headers' => ['Accept' => 'application/json'],
        ])->toArray();
    }
}
