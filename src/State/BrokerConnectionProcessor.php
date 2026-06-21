<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\BrokerConnection;
use App\Service\CredentialEncryption;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Encrypts any plaintext credentials supplied on write before persisting, then
 * clears them so they never linger in memory or get serialised back.
 *
 * @implements ProcessorInterface<BrokerConnection, BrokerConnection>
 */
final class BrokerConnectionProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CredentialEncryption $encryption,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BrokerConnection
    {
        \assert($data instanceof BrokerConnection);

        $credentials = $data->getCredentials();
        if (null !== $credentials) {
            $data->setEncryptedCredentials($this->encryption->encrypt($credentials));
            $data->setCredentials(null);
        }

        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }
}
