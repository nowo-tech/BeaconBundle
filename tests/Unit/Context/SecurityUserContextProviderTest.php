<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Context;

use Nowo\BeaconBundle\Context\SecurityUserContextProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class SecurityUserContextProviderTest extends TestCase
{
    public function testReturnsNullWithoutTokenStorage(): void
    {
        self::assertNull((new SecurityUserContextProvider())->getUserContext());
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
}
