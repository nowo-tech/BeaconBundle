<?php

declare(strict_types=1);

namespace App\Controller;

use App\Message\DemoRemoteRetryMessage;
use App\MessageHandler\DemoRemoteRetryHandler;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

use function bin2hex;
use function is_dir;
use function is_file;
use function mkdir;
use function random_bytes;
use function touch;
use function unlink;

/**
 * Remote retry demo (BeaconBundle 1.16+, `nowo_beacon.messenger.remote_retry`).
 *
 * 1. `GET /messenger-remote-retry` takes the demo gateway down and dispatches a message to the Doctrine
 *    `async` transport. `make worker` fails it twice (one retry) and stores it in `failed`; Beacon receives
 *    the final failure with `extra.messenger.failure_transport` / `failure_message_id`.
 * 2. `GET /messenger-remote-retry?fix=1` brings the gateway back.
 * 3. Click Retry on the event in Beacon: the worker claims the command, re-queues the message to `async`
 *    and handles it successfully (Discard removes it instead).
 */
final class DemoRemoteRetryController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var')]
        private readonly string $varDir,
    ) {
    }

    #[Route(path: '/messenger-remote-retry', name: 'demo_messenger_remote_retry', methods: ['GET'])]
    public function __invoke(Request $request, MessageBusInterface $bus): JsonResponse
    {
        $flag = $this->varDir . DemoRemoteRetryHandler::GATEWAY_DOWN_FLAG;

        if ($request->query->getBoolean('fix')) {
            if (is_file($flag)) {
                unlink($flag);
            }

            return new JsonResponse([
                'gateway' => 'up',
                'next'    => 'Click Retry on the failed event in Beacon; the worker (make worker) re-queues and handles it.',
            ]);
        }

        if (!is_dir($this->varDir)) {
            mkdir($this->varDir, 0o775, true);
        }
        touch($flag);

        $invoice = 'INV-' . bin2hex(random_bytes(3));
        $bus->dispatch(new DemoRemoteRetryMessage($invoice));

        return new JsonResponse([
            'dispatched' => $invoice,
            'gateway'    => 'down',
            'next'       => 'Run `make worker`: the message fails, is retried once and lands in the `failed` transport; then open /messenger-remote-retry?fix=1 and click Retry in Beacon.',
        ]);
    }
}
