<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use App\Repository\BrokerSyncRunRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Audit record for a single broker sync attempt against one connection: whether
 * the statement was fetched, how many rows were imported/skipped, and any note
 * (error or row-level warning). Written by BrokerSyncService on every run so the
 * outcome — including failures — is visible in the UI rather than only on the
 * console.
 *
 * Read-only over the API; scoped to the user's accounts via CurrentUserExtension.
 */
#[ORM\Entity(repositoryClass: BrokerSyncRunRepository::class)]
#[ORM\Index(name: 'idx_sync_connection', columns: ['broker_connection_id', 'created_at'])]
#[ApiResource(
    shortName: 'BrokerSyncRun',
    operations: [
        new GetCollection(),
        new Get(security: 'is_granted("VIEW", object.getAccount())'),
    ],
    normalizationContext: ['groups' => ['brokersyncrun:read']],
    order: ['createdAt' => 'DESC'],
)]
#[ApiFilter(SearchFilter::class, properties: ['brokerConnection' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt'], arguments: ['orderParameterName' => 'order'])]
class BrokerSyncRun
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['brokersyncrun:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: BrokerConnection::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['brokersyncrun:read'])]
    private BrokerConnection $brokerConnection;

    /** Denormalised account, mirrored from the connection, so queries scope cheaply. */
    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['brokersyncrun:read'])]
    private Account $account;

    #[ORM\Column]
    #[Groups(['brokersyncrun:read'])]
    private bool $fetched;

    #[ORM\Column]
    #[Groups(['brokersyncrun:read'])]
    private int $imported;

    #[ORM\Column]
    #[Groups(['brokersyncrun:read'])]
    private int $skipped;

    /** Free-text outcome: an error reason when the pull failed, or a row-error summary. */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['brokersyncrun:read'])]
    private ?string $note = null;

    #[ORM\Column]
    #[Groups(['brokersyncrun:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct(BrokerConnection $brokerConnection, bool $fetched, int $imported, int $skipped, ?string $note = null)
    {
        $this->id = Uuid::v7();
        $this->brokerConnection = $brokerConnection;
        $this->account = $brokerConnection->getAccount();
        $this->fetched = $fetched;
        $this->imported = $imported;
        $this->skipped = $skipped;
        $this->note = $note;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getBrokerConnection(): BrokerConnection
    {
        return $this->brokerConnection;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function isFetched(): bool
    {
        return $this->fetched;
    }

    public function getImported(): int
    {
        return $this->imported;
    }

    public function getSkipped(): int
    {
        return $this->skipped;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
