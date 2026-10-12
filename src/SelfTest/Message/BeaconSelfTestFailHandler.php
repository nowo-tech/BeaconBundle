<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\SelfTest\Message;

use Nowo\BeaconBundle\SelfTest\BeaconSelfTestException;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

use function sprintf;

/**
 * Always fails without retry so the Messenger failure listener reports on the first attempt.
 *
 * Registered only when symfony/messenger is installed.
 */
final readonly class BeaconSelfTestFailHandler
{
    public function __invoke(BeaconSelfTestFailMessage $message): never
    {
        throw new UnrecoverableMessageHandlingException(sprintf('Beacon self-test: Messenger handler failure (run %s)', $message->runToken), 0, new BeaconSelfTestException(sprintf('Beacon self-test: worker exception (run %s)', $message->runToken)));
    }
}
