<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Context;

use Nowo\BeaconBundle\Context\SecurityUserContextProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

final class SecurityUserContextProviderTest extends TestCase
{
    public function testReturnsNullWithoutTokenStorage(): void
    {
        self::assertNull((new SecurityUserContextProvider())->getUserContext());
    }

    public function testReturnsNullWhenTokenMissingOrUserInvalid(): void
    {
        $storage = new TokenStorage();
        self::assertNull((new SecurityUserContextProvider($storage))->getUserContext());

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(null);
        $storage->setToken($token);

        self::assertNull((new SecurityUserContextProvider($storage))->getUserContext());
    }

    public function testReturnsUserSummary(): void
    {
        $user    = new InMemoryUser('alice@example.com', null, ['ROLE_USER']);
        $token   = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $storage = new TokenStorage();
        $storage->setToken($token);

        $context = (new SecurityUserContextProvider($storage))->getUserContext();
        self::assertSame('alice@example.com', $context['id'] ?? null);
        self::assertSame('alice@example.com', $context['username'] ?? null);
    }

    public function testIncludesEmailWhenUserExposesGetEmail(): void
    {
        $user = new class implements UserInterface {
            public function getRoles(): array
            {
                return ['ROLE_USER'];
            }

            public function getUserIdentifier(): string
            {
                return '42';
            }

            public function getEmail(): string
            {
                return 'alice@example.com';
            }
        };

        $token   = new UsernamePasswordToken($user, 'main', $user->getRoles());
        $storage = new TokenStorage();
        $storage->setToken($token);

        $context = (new SecurityUserContextProvider($storage))->getUserContext();
        self::assertSame('alice@example.com', $context['email'] ?? null);
    }
}
