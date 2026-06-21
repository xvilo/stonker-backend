<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\MembershipRole;
use App\Repository\AccountMembershipRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: AccountMembershipRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_membership_account_user', columns: ['account_id', 'user_id'])]
class AccountMembership
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    #[Groups(['account:read', 'user:read'])]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Account::class, inversedBy: 'memberships')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['user:read'])]
    private Account $account;

    #[ORM\ManyToOne(targetEntity: User::class, inversedBy: 'memberships')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['account:read'])]
    private User $user;

    #[ORM\Column(enumType: MembershipRole::class)]
    #[Groups(['account:read', 'user:read'])]
    private MembershipRole $role;

    #[ORM\Column]
    #[Groups(['account:read', 'user:read'])]
    private \DateTimeImmutable $joinedAt;

    public function __construct(Account $account, User $user, MembershipRole $role)
    {
        $this->id = Uuid::v7();
        $this->account = $account;
        $this->user = $user;
        $this->role = $role;
        $this->joinedAt = new \DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getAccount(): Account
    {
        return $this->account;
    }

    public function setAccount(Account $account): static
    {
        $this->account = $account;

        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRole(): MembershipRole
    {
        return $this->role;
    }

    public function setRole(MembershipRole $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getJoinedAt(): \DateTimeImmutable
    {
        return $this->joinedAt;
    }
}
