<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\DemoRemoteRetryMessage;
use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

use function is_file;

/**
 * Fails while the demo "gateway" is down (flag file present), so the message ends in the `failed`
 * transport; once the gateway is fixed, Retry in Beacon puts it back on `async` and it succeeds.
 */
#[AsMessageHandler]
final class DemoRemoteRetryHandler
{
    public const GATEWAY_DOWN_FLAG = '/demo-remote-retry-gateway-down';

    public function __construct(
        #[Autowire('%kernel.project_dir%/var')]
        private readonly string $varDir,
    ) {
    }

    public function __invoke(DemoRemoteRetryMessage $message): void
    {
        if (is_file($this->varDir . self::GATEWAY_DOWN_FLAG)) {
            throw new RuntimeException('Beacon demo: payment gateway timeout (retry it from Beacon once the gateway is back).');
        }
    }
}
