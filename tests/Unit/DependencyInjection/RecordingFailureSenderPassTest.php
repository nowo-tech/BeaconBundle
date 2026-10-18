<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\DependencyInjection;

use Nowo\BeaconBundle\DependencyInjection\Compiler\RecordingFailureSenderPass;
use Nowo\BeaconBundle\Messenger\FailureTransport\FailureTransportRecorder;
use Nowo\BeaconBundle\Messenger\FailureTransport\RecordingFailureSender;
use Nowo\BeaconBundle\NowoBeaconBundle;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use stdClass;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class RecordingFailureSenderPassTest extends TestCase
{
    public function testCompiledListenerUsesRecordingSendersAndOriginalLocatorIsUntouched(): void
    {
        $container = new ContainerBuilder();
        (new NowoBeaconBundle())->build($container);
        $container->setDefinition(FailureTransportRecorder::class, new Definition(FailureTransportRecorder::class));
        $container->setDefinition('messenger.transport.failed', new Definition(InMemoryTransport::class));
        $container->setDefinition('custom_failure', new Definition(InMemoryTransport::class));

        $shared = ServiceLocatorTagPass::register($container, [
            'async'  => new Reference('messenger.transport.failed'),
            'orders' => new Reference('custom_failure'),
        ]);
        $container->setDefinition('another_consumer', (new Definition(stdClass::class))->setProperty('locator', $shared)->setPublic(true));
        $container->setDefinition(RecordingFailureSenderPass::LISTENER_ID, (new Definition(SendFailedMessageToFailureTransportListener::class, [
            $shared,
            null,
            ['async' => 'failed', 'orders' => 'custom_failure'],
        ]))->setPublic(true));

        $container->compile(true);

        $listener = $container->get(RecordingFailureSenderPass::LISTENER_ID);
        self::assertInstanceOf(SendFailedMessageToFailureTransportListener::class, $listener);
        $senders = (new ReflectionProperty(SendFailedMessageToFailureTransportListener::class, 'failureSenders'))->getValue($listener);
        self::assertInstanceOf(ContainerInterface::class, $senders);
        self::assertInstanceOf(RecordingFailureSender::class, $senders->get('async'));
        self::assertInstanceOf(RecordingFailureSender::class, $senders->get('orders'));

        /** @var object{locator: ContainerInterface} $other */
        $other = $container->get('another_consumer');
        self::assertInstanceOf(InMemoryTransport::class, $other->locator->get('async'), 'other consumers of the shared locator keep the raw transport');
    }

    public function testWrapsReferencesAndKeepsOtherValues(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(FailureTransportRecorder::class, new Definition(FailureTransportRecorder::class));
        $locator = ServiceLocatorTagPass::register($container, [
            'async' => new Reference('messenger.transport.failed'),
            'raw'   => new Reference('custom_failure'),
        ]);
        $locatorDefinition = $container->getDefinition((string) $locator);
        $map               = $locatorDefinition->getArgument(0);
        $map['plain']      = new Reference('messenger.transport.failed'); // not wrapped in a closure
        $map['weird']      = new ServiceClosureArgument(new Definition(InMemoryTransport::class));
        $locatorDefinition->replaceArgument(0, $map);
        $container->setDefinition(RecordingFailureSenderPass::LISTENER_ID, new Definition(SendFailedMessageToFailureTransportListener::class, [$locator]));

        (new RecordingFailureSenderPass())->process($container);

        $newLocator = $container->getDefinition(RecordingFailureSenderPass::LISTENER_ID)->getArgument(0);
        self::assertInstanceOf(Reference::class, $newLocator);
        $newMap = $container->getDefinition((string) $newLocator)->getArgument(0);

        self::assertEquals(new ServiceClosureArgument(new Reference(RecordingFailureSenderPass::SENDER_ID_PREFIX . 'failed')), $newMap['async']);
        self::assertEquals(new ServiceClosureArgument(new Reference(RecordingFailureSenderPass::SENDER_ID_PREFIX . 'failed')), $newMap['plain']);
        self::assertEquals(new ServiceClosureArgument(new Reference(RecordingFailureSenderPass::SENDER_ID_PREFIX . 'custom_failure')), $newMap['raw']);
        self::assertInstanceOf(Definition::class, $newMap['weird']->getValues()[0]);

        $sender = $container->getDefinition(RecordingFailureSenderPass::SENDER_ID_PREFIX . 'failed');
        self::assertSame(RecordingFailureSender::class, $sender->getClass());
        self::assertEquals([new Reference('messenger.transport.failed'), 'failed', new Reference(FailureTransportRecorder::class)], $sender->getArguments());
    }

    public function testDoesNothingWithoutRecorderListenerOrLocator(): void
    {
        $container = new ContainerBuilder();
        (new RecordingFailureSenderPass())->process($container); // nothing registered at all

        $container->setDefinition(RecordingFailureSenderPass::LISTENER_ID, new Definition(SendFailedMessageToFailureTransportListener::class, ['not a reference']));
        (new RecordingFailureSenderPass())->process($container); // no recorder
        self::assertSame('not a reference', $container->getDefinition(RecordingFailureSenderPass::LISTENER_ID)->getArgument(0));

        $container->setDefinition(FailureTransportRecorder::class, new Definition(FailureTransportRecorder::class));
        (new RecordingFailureSenderPass())->process($container); // argument is not a locator reference
        self::assertSame('not a reference', $container->getDefinition(RecordingFailureSenderPass::LISTENER_ID)->getArgument(0));

        $container->getDefinition(RecordingFailureSenderPass::LISTENER_ID)->replaceArgument(0, new Reference('missing_locator'));
        (new RecordingFailureSenderPass())->process($container);
        self::assertEquals(new Reference('missing_locator'), $container->getDefinition(RecordingFailureSenderPass::LISTENER_ID)->getArgument(0));

        $container->setDefinition('empty_locator', new Definition(stdClass::class, [[]]));
        $container->getDefinition(RecordingFailureSenderPass::LISTENER_ID)->replaceArgument(0, new Reference('empty_locator'));
        (new RecordingFailureSenderPass())->process($container);
        self::assertEquals(new Reference('empty_locator'), $container->getDefinition(RecordingFailureSenderPass::LISTENER_ID)->getArgument(0));

        $container->setDefinition(RecordingFailureSenderPass::LISTENER_ID, new Definition(SendFailedMessageToFailureTransportListener::class));
        (new RecordingFailureSenderPass())->process($container); // no arguments at all
        self::assertSame([], $container->getDefinition(RecordingFailureSenderPass::LISTENER_ID)->getArguments());
    }
}
