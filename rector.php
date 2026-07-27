<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Symfony\Symfony80\Rector\Class_\RemoveEraseCredentialsRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    ->withPhpVersion(PhpVersion::PHP_82)
    ->withComposerBased(symfony: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    )
    ->withSkip([
        __DIR__ . '/demo',
        __DIR__ . '/vendor',
        // UserInterface::eraseCredentials() is still required on Symfony 7.4 (CI lock);
        // the Symfony 8 rule must not strip it from dual-version test stubs.
        RemoveEraseCredentialsRector::class => [
            __DIR__ . '/tests/Unit/Context/SecurityUserContextProviderTest.php',
        ],
    ]);
