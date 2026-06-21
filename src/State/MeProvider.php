<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/**
 * Backs GET /api/me with the currently authenticated user.
 *
 * @implements ProviderInterface<User>
 */
final class MeProvider implements ProviderInterface
{
    public function __construct(private readonly Security $security)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnauthorizedHttpException('Bearer', 'Authentication required.');
        }

        return $user;
    }
}
