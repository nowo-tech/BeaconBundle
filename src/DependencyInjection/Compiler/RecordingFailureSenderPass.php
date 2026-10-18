<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\DependencyInjection\Compiler;

use Nowo\BeaconBundle\Messenger\FailureTransport\FailureTransportRecorder;
use Nowo\BeaconBundle\Messenger\FailureTransport\RecordingFailureSender;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function array_key_first;
use function is_array;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Wraps every failure sender of Symfony's `SendFailedMessageToFailureTransportListener` in a
 * {@see RecordingFailureSender}, so the failure-transport id of a final failure reaches Beacon.
 *
 * Only that listener's own sender locator is replaced (a new locator is registered: locators are
 * shared between consumers with the same map, so the original definition is never mutated). The
 * pass does nothing when the recorder is not registered (Messenger failure reporting off) or when
 * the Symfony service / its locator argument is missing (no failure transport, or a future Symfony
 * change): the failure ids are then simply not sent.
 */
final class RecordingFailureSenderPass implements CompilerPassInterface
{
    public const LISTENER_ID = 'messenger.failure.send_failed_message_to_failure_transport_listener';

    public const SENDER_ID_PREFIX = 'nowo_beacon.recording_failure_sender.';

    private const TRANSPORT_ID_PREFIX = 'messenger.transport.';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(FailureTransportRecorder::class) || !$container->hasDefinition(self::LISTENER_ID)) {
            return;
        }

        $listener  = $container->getDefinition(self::LISTENER_ID);
        $arguments = $listener->getArguments();
        $key       = array_key_first($arguments);
        $locatorId = $key !== null ? $arguments[$key] : null;
        if (!$locatorId instanceof Reference || !$container->hasDefinition((string) $locatorId)) {
            return;
        }

        $map = $container->getDefinition((string) $locatorId)->getArguments()[0] ?? null;
        if (!is_array($map) || $map === []) {
            return;
        }

        $wrapped = [];
        foreach ($map as $receiverName => $value) {
            $inner = $value instanceof ServiceClosureArgument ? ($value->getValues()[0] ?? null) : $value;
            if (!$inner instanceof Reference) {
                $wrapped[$receiverName] = $inner;

                continue;
            }

            $wrapped[$receiverName] = new Reference($this->recordingSender($container, (string) $inner));
        }

        $listener->replaceArgument($key, ServiceLocatorTagPass::register($container, $wrapped));
    }

    /**
     * Id of the (shared per failure transport) recording sender wrapping `$innerId`.
     */
    private function recordingSender(ContainerBuilder $container, string $innerId): string
    {
        $name = str_starts_with($innerId, self::TRANSPORT_ID_PREFIX)
            ? substr($innerId, strlen(self::TRANSPORT_ID_PREFIX))
            : $innerId;
        $id = self::SENDER_ID_PREFIX . $name;

        if (!$container->hasDefinition($id)) {
            $definition = new Definition(RecordingFailureSender::class, [
                new Reference($innerId),
                $name,
                new Reference(FailureTransportRecorder::class),
            ]);
            $definition->setPublic(false);
            $container->setDefinition($id, $definition);
        }

        return $id;
    }
}
