<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use App\Enum\InstrumentType;
use App\Repository\InstrumentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A tradable security (stock or ETF). Shared catalog across accounts and
 * keyed by ISIN where available. `currency` is the instrument's trading
 * currency — transactions and prices inherit it (we track natively, no FX).
 *
 * The catalog is shared, so any authenticated user may read and add instruments.
 */
#[ORM\Entity(repositoryClass: InstrumentRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_instrument_isin', columns: ['isin'])]
#[UniqueEntity(fields: ['isin'], message: 'An instrument with this ISIN already exists.', ignoreNull: true)]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        new Post(),
        new Patch(),
    ],
    normalizationContext: ['groups' => ['instrument:read']],
    denormalizationContext: ['groups' => ['instrument:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['symbol' => 'partial', 'isin' => 'exact', 'name' => 'partial', 'type' => 'exact'])]
class Instrument
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['instrument:read', 'transaction:read'])]
    private Uuid $id;

    #[ORM\Column(length: 32)]
    #[Assert\NotBlank]
    #[Groups(['instrument:read', 'instrument:write', 'transaction:read'])]
    private string $symbol;

    #[ORM\Column(length: 12, nullable: true)]
    #[Groups(['instrument:read', 'instrument:write', 'transaction:read'])]
    private ?string $isin = null;

    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    #[Groups(['instrument:read', 'instrument:write', 'transaction:read'])]
    private string $name;

    #[ORM\Column(enumType: InstrumentType::class)]
    #[Groups(['instrument:read', 'instrument:write', 'transaction:read'])]
    private InstrumentType $type;

    #[ORM\Column(length: 3)]
    #[Assert\NotBlank]
    #[Assert\Length(exactly: 3)]
    #[Groups(['instrument:read', 'instrument:write', 'transaction:read'])]
    private string $currency;

    #[ORM\Column(length: 32, nullable: true)]
    #[Groups(['instrument:read', 'instrument:write'])]
    private ?string $exchange = null;

    #[ORM\Column]
    #[Groups(['instrument:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $symbol, string $name, InstrumentType $type, string $currency, ?string $isin = null, ?string $exchange = null)
    {
        $this->id = Uuid::v7();
        $this->symbol = $symbol;
        $this->name = $name;
        $this->type = $type;
        $this->currency = strtoupper($currency);
        $this->isin = $isin;
        $this->exchange = $exchange;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function setSymbol(string $symbol): static
    {
        $this->symbol = $symbol;

        return $this;
    }

    public function getIsin(): ?string
    {
        return $this->isin;
    }

    public function setIsin(?string $isin): static
    {
        $this->isin = $isin;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getType(): InstrumentType
    {
        return $this->type;
    }

    public function setType(InstrumentType $type): static
    {
        $this->type = $type;

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

    public function getExchange(): ?string
    {
        return $this->exchange;
    }

    public function setExchange(?string $exchange): static
    {
        $this->exchange = $exchange;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
