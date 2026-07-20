<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Context;

use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_string;
use function method_exists;

/**
 * Reads the current Security token when available.
 */
final class SecurityUserContextProvider implements UserContextProviderInterface
{
    public function __construct(
        private readonly ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    public function getUserContext(): ?array
    {
        if ($this->tokenStorage === null) {
            return null;
        }

        $token = $this->tokenStorage->getToken();
        if ($token === null) {
            return null;
        }

        $user = $token->getUser();
        if (!$user instanceof UserInterface) {
            return null;
        }

        $context = [
            'id'       => $user->getUserIdentifier(),
            'username' => $user->getUserIdentifier(),
        ];

        if (method_exists($user, 'getEmail')) {
            $email = $user->getEmail();
            if (is_string($email) && $email !== '') {
                $context['email'] = $email;
            }
        }

        return $context;
    }
}
