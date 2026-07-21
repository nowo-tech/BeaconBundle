<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Scope;

use Nowo\BeaconBundle\Scope\Scope;
use PHPUnit\Framework\TestCase;

final class ScopeTest extends TestCase
{
    public function testSetTagsMergesAndOverwrites(): void
    {
        $scope = new Scope();
        $scope->setTag('env', 'prod');
        $scope->setTags(['region' => 'eu', 'env' => 'staging']);

        self::assertSame([
            'env'    => 'staging',
            'region' => 'eu',
        ], $scope->getTags());
    }

    public function testIgnoresInvalidValuesAndCapsCount(): void
    {
        $scope = new Scope();
        $scope->setTag('ok', 'yes');
        $scope->setTag('bad', ['nested' => true]);
        $scope->setTag('', 'x');

        for ($i = 0; $i < Scope::MAX_TAGS + 5; ++$i) {
            $scope->setTag('k' . $i, (string) $i);
        }

        self::assertCount(Scope::MAX_TAGS, $scope->getTags());
        self::assertSame('yes', $scope->getTags()['ok']);
        self::assertArrayNotHasKey('bad', $scope->getTags());
    }

    public function testClearAndRemove(): void
    {
        $scope = new Scope();
        $scope->setTags(['a' => '1', 'b' => '2']);
        $scope->removeTag('a');
        self::assertSame(['b' => '2'], $scope->getTags());
        $scope->clearTags();
        self::assertSame([], $scope->getTags());
    }
}
