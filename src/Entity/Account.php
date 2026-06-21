<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\AccountRepository;
use App\State\AccountProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An account is the tenant boundary: transactions, broker connections and
 * imports all belong to one account. Users join accounts via memberships,
 * which is what makes inviting collaborators possible later.
 *
 * Collection queries are scoped to the current user by CurrentUserExtension;
 * item-level write access is enforced by AccountVoter.
 */
#[ORM\Entity(repositoryClass: AccountRepository::class)]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(security: 'is_granted("VIEW", object)'),
        new Post(processor: AccountProcessor::class),
        new Patch(security: 'is_granted("MANAGE", object)'),
        new Delete(security: 'is_granted("MANAGE", object)'),
    ],
    normalizationContext: ['groups' => ['account:read']],
    denormalizationContext: ['groups' => ['account:write']],
)]
class Account
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['account:read', 'user:read', 'invitation:read'])]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['account:read', 'account:write', 'user:read', 'invitation:read'])]
    private string $name;

    #[ORM\Column]
    #[Groups(['account:read'])]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, AccountMembership> */
    #[ORM\OneToMany(targetEntity: AccountMembership::class, mappedBy: 'account', cascade: ['persist'], orphanRemoval: true)]
    #[Groups(['account:read'])]
    private Collection $memberships;

    /** @var Collection<int, Transaction> */
    #[ORM\OneToMany(targetEntity: Transaction::class, mappedBy: 'account', orphanRemoval: true)]
    private Collection $transactions;

    /** @var Collection<int, BrokerConnection> */
    #[ORM\OneToMany(targetEntity: BrokerConnection::class, mappedBy: 'account', orphanRemoval: true)]
    private Collection $brokerConnections;

    public function __construct(string $name)
    {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
        $this->memberships = new ArrayCollection();
        $this->transactions = new ArrayCollection();
        $this->brokerConnections = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, AccountMembership> */
    public function getMemberships(): Collection
    {
        return $this->memberships;
    }

    public function addMembership(AccountMembership $membership): static
    {
        if (!$this->memberships->contains($membership)) {
            $this->memberships->add($membership);
            $membership->setAccount($this);
        }

        return $this;
    }

    /** @return Collection<int, Transaction> */
    public function getTransactions(): Collection
    {
        return $this->transactions;
    }

    /** @return Collection<int, BrokerConnection> */
    public function getBrokerConnections(): Collection
    {
        return $this->brokerConnections;
    }

    public function hasMember(User $user): bool
    {
        foreach ($this->memberships as $membership) {
            if ($membership->getUser() === $user) {
                return true;
            }
        }

        return false;
    }
}
