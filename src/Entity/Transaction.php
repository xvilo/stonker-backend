<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Enum\BrokerType;
use App\Enum\TransactionSource;
use App\Enum\TransactionType;
use App\Repository\TransactionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A single buy or sell of an instrument within an account, including the
 * transaction fee. Monetary fields are stored as exact DECIMAL strings and
 * must only be combined through BigDecimal arithmetic — never PHP floats.
 *
 * `externalId` deduplicates imported rows; the unique constraint over
 * (account, brokerType, externalId) makes re-imports idempotent. Manual rows
 * leave it NULL, which Postgres treats as distinct, so they never collide.
 */
#[ORM\Entity(repositoryClass: TransactionRepository::class)]
#[ORM\Index(name: 'idx_tx_account_instrument', columns: ['account_id', 'instrument_id'])]
#[ORM\Index(name: 'idx_tx_account_date', columns: ['account_id', 'trade_date'])]
#[ORM\UniqueConstraint(name: 'uniq_tx_external', columns: ['account_id', 'broker_type', 'external_id'])]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(securityPostDenormalize: 'is_granted("EDIT", object.getAccount())'),
        new Patch(securityPostDenormalize: 'is_granted("EDIT", object.getAccount())'),
        new Delete(security: 'is_granted("EDIT", object.getAccount())'),
    ],
    normalizationContext: ['groups' => ['transaction:read']],
    denormalizationContext: ['groups' => ['transaction:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['account' => 'exact', 'instrument' => 'exact', 'type' => 'exact', 'brokerType' => 'exact'])]
#[ApiFilter(DateFilter::class, properties: ['tradeDate'])]
#[ApiFilter(OrderFilter::class, properties: ['tradeDate', 'createdAt'], arguments: ['orderParameterName' => 'order'])]
class Transaction
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['transaction:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Account::class, inversedBy: 'transactions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    #[Groups(['transaction:read', 'transaction:write'])]
    private Account $account;

    #[ORM\ManyToOne(targetEntity: Instrument::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['transaction:read', 'transaction:write'])]
    private Instrument $instrument;

    #[ORM\Column(enumType: BrokerType::class)]
    #[Groups(['transaction:read', 'transaction:write'])]
    private BrokerType $brokerType;

    #[ORM\Column(enumType: TransactionType::class)]
    #[Groups(['transaction:read', 'transaction:write'])]
    private TransactionType $type;

    #[ORM\Column]
    #[Assert\NotNull]
    #[Groups(['transaction:read', 'transaction:write'])]
    private \DateTimeImmutable $tradeDate;

    #[ORM\Column(type: 'decimal', precision: 24, scale: 8)]
    #[Assert\NotBlank]
    #[Assert\Positive]
    #[Groups(['transaction:read', 'transaction:write'])]
    private string $quantity;

    #[ORM\Column(type: 'decimal', precision: 20, scale: 8)]
    #[Assert\NotBlank]
    #[Assert\PositiveOrZero]
    #[Groups(['transaction:read', 'transaction:write'])]
    private string $pricePerShare;

    #[ORM\Column(length: 3)]
    #[Groups(['transaction:read', 'transaction:write'])]
    private string $currency;

    #[ORM\Column(type: 'decimal', precision: 20, scale: 4, options: ['default' => '0'])]
    #[Assert\PositiveOrZero]
    #[Groups(['transaction:read', 'transaction:write'])]
    private string $fee = '0';

    #[ORM\Column(length: 3)]
    #[Groups(['transaction:read', 'transaction:write'])]
    private string $feeCurrency;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['transaction:read', 'transaction:write'])]
    private ?string $notes = null;

    #[ORM\Column(enumType: TransactionSource::class)]
    #[Groups(['transaction:read'])]
    private TransactionSource $source = TransactionSource::MANUAL;

    #[ORM\Column(length: 128, nullable: true)]
    #[Groups(['transaction:read'])]
    private ?string $externalId = null;

    #[ORM\Column]
    #[Groups(['transaction:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        Account $account,
        Instrument $instrument,
        BrokerType $brokerType,
        TransactionType $type,
        \DateTimeImmutable $tradeDate,
        string $quantity,
        string $pricePerShare,
        ?string $currency = null,
        string $fee = '0',
        ?string $feeCurrency = null,
    ) {
        $this->id = Uuid::v7();
        $this->account = $account;
        $this->instrument = $instrument;
        $this->brokerType = $brokerType;
        $this->type = $type;
        $this->tradeDate = $tradeDate;
        $this->quantity = $quantity;
        $this->pricePerShare = $pricePerShare;
        $this->currency = strtoupper($currency ?? $instrument->getCurrency());
        $this->fee = $fee;
        $this->feeCurrency = strtoupper($feeCurrency ?? $this->currency);
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

    public function getInstrument(): Instrument
    {
        return $this->instrument;
    }

    public function setInstrument(Instrument $instrument): static
    {
        $this->instrument = $instrument;

        return $this;
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

    public function getType(): TransactionType
    {
        return $this->type;
    }

    public function setType(TransactionType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getTradeDate(): \DateTimeImmutable
    {
        return $this->tradeDate;
    }

    public function setTradeDate(\DateTimeImmutable $tradeDate): static
    {
        $this->tradeDate = $tradeDate;

        return $this;
    }

    public function getQuantity(): string
    {
        return $this->quantity;
    }

    public function setQuantity(string $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getPricePerShare(): string
    {
        return $this->pricePerShare;
    }

    public function setPricePerShare(string $pricePerShare): static
    {
        $this->pricePerShare = $pricePerShare;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = strtoupper($currency);

        return $this;
    }

    public function getFee(): string
    {
        return $this->fee;
    }

    public function setFee(string $fee): static
    {
        $this->fee = $fee;

        return $this;
    }

    public function getFeeCurrency(): string
    {
        return $this->feeCurrency;
    }

    public function setFeeCurrency(string $feeCurrency): static
    {
        $this->feeCurrency = strtoupper($feeCurrency);

        return $this;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;

        return $this;
    }

    public function getSource(): TransactionSource
    {
        return $this->source;
    }

    public function setSource(TransactionSource $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): static
    {
        $this->externalId = $externalId;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
