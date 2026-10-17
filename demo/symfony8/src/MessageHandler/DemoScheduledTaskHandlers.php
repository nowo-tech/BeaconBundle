<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\DemoFlakyTaskMessage;
use App\Message\DemoHeartbeatMessage;
use App\Message\DemoSlowSyncMessage;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function sleep;

/**
 * Handlers of the demo schedule ({@see \App\Scheduler\DemoSchedule}).
 */
final class DemoScheduledTaskHandlers
{
    #[AsMessageHandler]
    public function heartbeat(DemoHeartbeatMessage $message): void
    {
    }

    #[AsMessageHandler]
    public function slowSync(DemoSlowSyncMessage $message): void
    {
        sleep($message->sleepSeconds);
    }

    #[AsMessageHandler]
    public function flaky(DemoFlakyTaskMessage $message): void
    {
        throw new RuntimeException('Beacon demo scheduled task failure (check-in status error).');
    }
}
