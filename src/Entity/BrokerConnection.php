<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Enum\BrokerType;
use App\Repository\BrokerConnectionRepository;
use App\State\BrokerConnectionProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Stored credentials for an automated broker pull (currently IBKR Flex Query).
 * Secrets are encrypted at rest via CredentialEncryption and never serialised
 * back out over the API — the plaintext only exists transiently on write.
 *
 * Reads are scoped to the user's accounts; writes require MANAGE (OWNER).
 */
#[ORM\Entity(repositoryClass: BrokerConnectionRepository::class)]
#[ApiResource(
    shortName: 'BrokerConnection',
    operations: [
        new GetCollection(),
        new Get(security: 'is_granted("VIEW", object.getAccount())'),
        new Post(securityPostDenormalize: 'is_granted("MANAGE", object.getAccount())', processor: BrokerConnectionProcessor::class),
        new Patch(security: 'is_granted("MANAGE", object.getAccount())', processor: BrokerConnectionProcessor::class),
        new Delete(security: 'is_granted("MANAGE", object.getAccount())'),
    ],
    normalizationContext: ['groups' => ['brokerconnection:read']],
    denormalizationContext: ['groups' => ['brokerconnection:write']],
)]
class BrokerConnection
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['brokerconnection:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Account::class, inversedBy: 'brokerConnections')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    #[Groups(['brokerconnection:read', 'brokerconnection:write'])]
    private Account $account;

    #[ORM\Column(enumType: BrokerType::class)]
    #[Groups(['brokerconnection:read', 'brokerconnection:write'])]
    private BrokerType $brokerType;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['brokerconnection:read', 'brokerconnection:write'])]
    private string $label;

    /** Encrypted JSON blob of provider credentials (e.g. IBKR Flex token + query id). Never serialised. */
    #[ORM\Column(type: 'text')]
    private string $encryptedCredentials;

    /**
     * Transient plaintext credentials supplied on write (e.g. {"token": "...", "queryId": "..."}).
     * Encrypted by the processor and never read back.
     *
     * @var array<string, mixed>|null
     */
    #[Groups(['brokerconnection:write'])]
    private ?array $credentials = null;

    #[ORM\Column]
    #[Groups(['brokerconnection:read', 'brokerconnection:write'])]
    private bool $active = true;

    #[ORM\Column(nullable: true)]
    #[Groups(['brokerconnection:read'])]
    private ?\DateTimeImmutable $lastSyncAt = null;

    /** Set when a sync fails and no retry window is open; cleared on the next success. */
    #[ORM\Column(nullable: true)]
    #[Groups(['brokerconnection:read'])]
    private ?\DateTimeImmutable $retryUntil = null;

    #[ORM\Column]
    #[Groups(['brokerconnection:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct(Account $account, BrokerType $brokerType, string $label, string $encryptedCredentials = '')
    {
        $this->id = Uuid::v7();
        $this->account = $account;
        $this->brokerType = $brokerType;
        $this->label = $label;
        $this->encryptedCredentials = $encryptedCredentials;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function getBrokerType(): BrokerType
    {
        return $this->brokerType;
    }

    public function setBrokerType(BrokerType $brokerType): static
    {
        $this->brokerType = $brokerType;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getEncryptedCredentials(): string
    {
        return $this->encryptedCredentials;
    }

    public function setEncryptedCredentials(string $encryptedCredentials): static
    {
        $this->encryptedCredentials = $encryptedCredentials;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCredentials(): ?array
    {
        return $this->credentials;
    }

    /**
     * @param array<string, mixed>|null $credentials
     */
    public function setCredentials(?array $credentials): static
    {
        $this->credentials = $credentials;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getLastSyncAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncAt;
    }

    public function setLastSyncAt(?\DateTimeImmutable $lastSyncAt): static
    {
        $this->lastSyncAt = $lastSyncAt;

        return $this;
    }

    public function getRetryUntil(): ?\DateTimeImmutable
    {
        return $this->retryUntil;
    }

    public function setRetryUntil(?\DateTimeImmutable $retryUntil): static
    {
        $this->retryUntil = $retryUntil;

        return $this;
    }

    /**
     * Updates the retry window after a sync attempt: a success closes any open
     * window; a failure opens a fresh 6h window only if none is currently
     * active, so repeated failures don't keep pushing the deadline out.
     */
    public function recordSyncOutcome(bool $fetched, \DateTimeImmutable $now): void
    {
        if ($fetched) {
            $this->retryUntil = null;
        } elseif (null === $this->retryUntil || $this->retryUntil < $now) {
            $this->retryUntil = $now->modify('+6 hours');
        }
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
