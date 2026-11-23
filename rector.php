<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\Symfony\CodeQuality\Rector\Class_\EventSubscriberToAttributeRector;
use Rector\Symfony\Symfony80\Rector\Class_\RemoveEraseCredentialsRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    // Machine-wide cap: at most 2 worker processes; generous timeout for slow (WSL2 / CI) hosts.
    ->withParallel(300, 2, 10)
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
        // Public API: these classes implement EventSubscriberInterface and expose a public static getSubscribedEvents();
        // converting them to #[AsEventListener] would remove that public method and interface (a BC break for hosts
        // that call or decorate them), which must not ship in a minor. Revisit at the next major.
        EventSubscriberToAttributeRector::class => [
            __DIR__ . '/src/EventListener/BeaconConsoleErrorListener.php',
            __DIR__ . '/src/EventListener/BeaconCredentialsArmListener.php',
            __DIR__ . '/src/EventListener/BeaconExceptionListener.php',
            __DIR__ . '/src/EventListener/BeaconMessengerTransactionListener.php',
            __DIR__ . '/src/EventListener/BeaconRequestTransactionListener.php',
            __DIR__ . '/src/EventListener/BeaconSchedulerCheckInListener.php',
            __DIR__ . '/src/EventListener/BeaconTraceRequestListener.php',
            __DIR__ . '/src/EventListener/BeaconMessengerRetryListener.php',
            __DIR__ . '/src/EventListener/BeaconWorkerMemoryListener.php',
            __DIR__ . '/src/Messenger/FailureTransport/FailureTransportRecorder.php',
            __DIR__ . '/src/Messenger/RemoteRetry/RemoteCommandPoller.php',
        ],
        // Public API: the rule drops a parameter of a public listener method (a signature change visible to hosts
        // that call it directly). Same files as above; revisit at the next major.
        RemoveUnusedPublicMethodParameterRector::class => [
            __DIR__ . '/src/EventListener/BeaconSchedulerCheckInListener.php',
            __DIR__ . '/src/Messenger/RemoteRetry/RemoteCommandPoller.php',
        ],
    ]);
