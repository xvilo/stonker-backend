<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\Enum\InvitationStatus;
use App\Enum\MembershipRole;
use App\Repository\InvitationRepository;
use App\State\InvitationAcceptProcessor;
use App\State\InvitationCreateProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A pending invite for an email address to join an account with a given role.
 * The invitee does not need to exist yet; accepting the token creates or links
 * a user and materialises an AccountMembership.
 */
#[ORM\Entity(repositoryClass: InvitationRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_invitation_token', columns: ['token'])]
#[ORM\Index(name: 'idx_invitation_email', columns: ['email'])]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Post(
            securityPostDenormalize: 'is_granted("MANAGE", object.getAccount())',
            processor: InvitationCreateProcessor::class,
        ),
        new Post(
            uriTemplate: '/invitations/{token}/accept',
            uriVariables: [
                'token' => new Link(fromClass: Invitation::class, identifiers: ['token']),
            ],
            read: false,
            deserialize: false,
            validate: false,
            processor: InvitationAcceptProcessor::class,
        ),
        new Delete(security: 'is_granted("MANAGE", object.getAccount())'),
    ],
    normalizationContext: ['groups' => ['invitation:read']],
    denormalizationContext: ['groups' => ['invitation:write']],
)]
class Invitation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['invitation:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    #[Groups(['invitation:read', 'invitation:write'])]
    private Account $account;

    #[ORM\Column(length: 180)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Groups(['invitation:read', 'invitation:write'])]
    private string $email;

    #[ORM\Column(enumType: MembershipRole::class)]
    #[Groups(['invitation:read', 'invitation:write'])]
    private MembershipRole $role;

    #[ORM\Column(length: 64)]
    #[Groups(['invitation:read'])]
    private string $token;

    #[ORM\Column(enumType: InvitationStatus::class)]
    #[Groups(['invitation:read'])]
    private InvitationStatus $status = InvitationStatus::PENDING;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $invitedBy = null;

    #[ORM\Column]
    #[Groups(['invitation:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    #[Groups(['invitation:read'])]
    private \DateTimeImmutable $expiresAt;

    public function __construct(Account $account, string $email, MembershipRole $role, ?User $invitedBy = null, ?\DateTimeImmutable $expiresAt = null)
    {
        $this->id = Uuid::v7();
        $this->account = $account;
        $this->email = strtolower($email);
        $this->role = $role;
        $this->invitedBy = $invitedBy;
        $this->token = bin2hex(random_bytes(24));
        $this->createdAt = new \DateTimeImmutable();
        $this->expiresAt = $expiresAt ?? new \DateTimeImmutable('+14 days');
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getRole(): MembershipRole
    {
        return $this->role;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function getStatus(): InvitationStatus
    {
        return $this->status;
    }

    public function setStatus(InvitationStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getInvitedBy(): ?User
    {
        return $this->invitedBy;
    }

    public function setInvitedBy(?User $invitedBy): static
    {
        $this->invitedBy = $invitedBy;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isPending(): bool
    {
        return $this->status === InvitationStatus::PENDING;
    }

    public function isExpired(): bool
    {
        return $this->expiresAt < new \DateTimeImmutable();
    }
}
