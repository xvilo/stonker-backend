<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use App\Repository\UserRepository;
use App\State\MeProvider;
use App\State\UserRegistrationProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'uniq_user_email', columns: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'An account with this email already exists.')]
#[ApiResource(
    shortName: 'User',
    operations: [
        new Post(
            uriTemplate: '/register',
            processor: UserRegistrationProcessor::class,
            denormalizationContext: ['groups' => ['user:register']],
            normalizationContext: ['groups' => ['user:read']],
            validationContext: ['groups' => ['Default', 'user:register']],
        ),
        new Get(
            uriTemplate: '/me',
            provider: MeProvider::class,
            normalizationContext: ['groups' => ['user:read']],
        ),
    ],
)]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['user:read', 'account:read'])]
    private Uuid $id;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Groups(['user:read', 'user:register', 'account:read'])]
    private string $email;

    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Groups(['user:read', 'user:register', 'account:read'])]
    private string $name;

    /** @var list<string> */
    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private string $password;

    /** Transient: the plaintext password supplied at registration, hashed by the processor. */
    #[Assert\NotBlank(groups: ['user:register'])]
    #[Assert\Length(min: 8, groups: ['user:register'])]
    #[Groups(['user:register'])]
    private ?string $plainPassword = null;

    #[ORM\Column]
    #[Groups(['user:read'])]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int, AccountMembership> */
    #[ORM\OneToMany(targetEntity: AccountMembership::class, mappedBy: 'user', orphanRemoval: true)]
    #[Groups(['user:read'])]
    private Collection $memberships;

    public function __construct(string $email, string $name)
    {
        $this->id = Uuid::v7();
        $this->email = $email;
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
        $this->memberships = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

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

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

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

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): static
    {
        $this->plainPassword = $plainPassword;

        return $this;
    }

    public function eraseCredentials(): void
    {
        $this->plainPassword = null;
    }
}
