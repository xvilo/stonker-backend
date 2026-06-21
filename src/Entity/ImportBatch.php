<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use App\Enum\BrokerType;
use App\Enum\ImportStatus;
use App\Enum\TransactionSource;
use App\Repository\ImportBatchRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

/**
 * Audit record for a CSV upload or scheduled Flex pull: what was imported,
 * what was skipped (duplicates) and any row-level errors.
 *
 * Read-only over the API (history); rows are created by ImportService. Uploads
 * happen via POST /api/accounts/{accountId}/imports (see ImportController).
 */
#[ORM\Entity(repositoryClass: ImportBatchRepository::class)]
#[ORM\Index(name: 'idx_import_account', columns: ['account_id', 'created_at'])]
#[ApiResource(
    shortName: 'ImportBatch',
    operations: [
        new GetCollection(),
        new Get(security: 'is_granted("VIEW", object.getAccount())'),
    ],
    normalizationContext: ['groups' => ['import:read']],
)]
#[ApiFilter(SearchFilter::class, properties: ['account' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt'], arguments: ['orderParameterName' => 'order'])]
class ImportBatch
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['import:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['import:read'])]
    private Account $account;

    #[ORM\Column(enumType: BrokerType::class)]
    #[Groups(['import:read'])]
    private BrokerType $brokerType;

    #[ORM\Column(enumType: TransactionSource::class)]
    #[Groups(['import:read'])]
    private TransactionSource $source;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['import:read'])]
    private ?string $fileName = null;

    #[ORM\Column(enumType: ImportStatus::class)]
    #[Groups(['import:read'])]
    private ImportStatus $status = ImportStatus::PENDING;

    #[ORM\Column]
    #[Groups(['import:read'])]
    private int $rowsImported = 0;

    #[ORM\Column]
    #[Groups(['import:read'])]
    private int $rowsSkipped = 0;

    /** @var list<string> */
    #[ORM\Column(type: 'json')]
    #[Groups(['import:read'])]
    private array $errors = [];

    #[ORM\Column]
    #[Groups(['import:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    #[Groups(['import:read'])]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct(Account $account, BrokerType $brokerType, TransactionSource $source, ?string $fileName = null)
    {
        $this->id = Uuid::v7();
        $this->account = $account;
        $this->brokerType = $brokerType;
        $this->source = $source;
        $this->fileName = $fileName;
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

    public function getSource(): TransactionSource
    {
        return $this->source;
    }

    public function getFileName(): ?string
    {
        return $this->fileName;
    }

    public function getStatus(): ImportStatus
    {
        return $this->status;
    }

    public function setStatus(ImportStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getRowsImported(): int
    {
        return $this->rowsImported;
    }

    public function setRowsImported(int $rowsImported): static
    {
        $this->rowsImported = $rowsImported;

        return $this;
    }

    public function getRowsSkipped(): int
    {
        return $this->rowsSkipped;
    }

    public function setRowsSkipped(int $rowsSkipped): static
    {
        $this->rowsSkipped = $rowsSkipped;

        return $this;
    }

    /** @return list<string> */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** @param list<string> $errors */
    public function setErrors(array $errors): static
    {
        $this->errors = $errors;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): static
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }
}
