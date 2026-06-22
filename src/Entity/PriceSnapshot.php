<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Enum\PriceSource;
use App\Repository\PriceSnapshotRepository;
use App\State\ManualPriceProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A daily closing price for an instrument. The time series powers current
 * valuation and the performance graph (last-known price is carried forward
 * for days without a snapshot).
 *
 * The POST operation is the manual price-entry fallback; it upserts by
 * (instrument, date) and always marks the source MANUAL.
 */
#[ORM\Entity(repositoryClass: PriceSnapshotRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_price_instrument_date', columns: ['instrument_id', 'date'])]
#[ORM\Index(name: 'idx_price_instrument_date', columns: ['instrument_id', 'date'])]
#[ApiResource(
    operations: [
        // Large page size so an instrument's full history returns in one request
        // for the detail chart (always filtered by instrument).
        new GetCollection(paginationItemsPerPage: 5000),
        new Post(processor: ManualPriceProcessor::class),
    ],
    normalizationContext: ['groups' => ['price:read']],
    denormalizationContext: ['groups' => ['price:write']],
)]
#[ApiFilter(SearchFilter::class, properties: ['instrument' => 'exact'])]
#[ApiFilter(DateFilter::class, properties: ['date'])]
#[ApiFilter(OrderFilter::class, properties: ['date'], arguments: ['orderParameterName' => 'order'])]
class PriceSnapshot
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['price:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Instrument::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    #[Groups(['price:read', 'price:write'])]
    private Instrument $instrument;

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull]
    #[Groups(['price:read', 'price:write'])]
    private \DateTimeImmutable $date;

    #[ORM\Column(type: 'decimal', precision: 20, scale: 8)]
    #[Assert\NotBlank]
    #[Assert\PositiveOrZero]
    #[Groups(['price:read', 'price:write'])]
    private string $close;

    #[ORM\Column(length: 3)]
    #[Groups(['price:read'])]
    private string $currency;

    #[ORM\Column(enumType: PriceSource::class)]
    #[Groups(['price:read'])]
    private PriceSource $source;

    #[ORM\Column]
    #[Groups(['price:read'])]
    private \DateTimeImmutable $createdAt;

    public function __construct(Instrument $instrument, \DateTimeImmutable $date, string $close, PriceSource $source = PriceSource::MANUAL, ?string $currency = null)
    {
        $this->id = Uuid::v7();
        $this->instrument = $instrument;
        $this->date = $date;
        $this->close = $close;
        $this->source = $source;
        $this->currency = strtoupper($currency ?? $instrument->getCurrency());
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getInstrument(): Instrument
    {
        return $this->instrument;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getClose(): string
    {
        return $this->close;
    }

    public function setClose(string $close): static
    {
        $this->close = $close;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getSource(): PriceSource
    {
        return $this->source;
    }

    public function setSource(PriceSource $source): static
    {
        $this->source = $source;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
