<?php

declare(strict_types=1);

namespace App\Tests\Messenger;

use Nowo\BeaconBundle\Messenger\FailureTransport\RecordingFailureSender;
use Nowo\BeaconBundle\Messenger\RemoteRetry\RemoteCommandPoller;
use Psr\Container\ContainerInterface;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\EventListener\SendFailedMessageToFailureTransportListener;

final class DemoRemoteRetryWiringTest extends KernelTestCase
{
    public function testFailureSendersAreRecordedAndThePollerIsWired(): void
    {
        $container = self::getContainer();

        $listener = $container->get('messenger.failure.send_failed_message_to_failure_transport_listener');
        self::assertInstanceOf(SendFailedMessageToFailureTransportListener::class, $listener);
        $senders = (new ReflectionProperty(SendFailedMessageToFailureTransportListener::class, 'failureSenders'))->getValue($listener);
        self::assertInstanceOf(ContainerInterface::class, $senders);
        self::assertInstanceOf(RecordingFailureSender::class, $senders->get('async'));

        self::assertTrue($container->has(RemoteCommandPoller::class));
    }
}
