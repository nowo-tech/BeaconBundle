<?php

declare(strict_types=1);

namespace App\Controller;

use App\Message\DemoFailMessage;
use InvalidArgumentException;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sample routes that exercise BeaconBundle capture APIs against a local Beacon DSN.
 */
final class DemoController extends AbstractController
{
    public function __construct(
        #[Autowire('%nowo.beacon.enabled%')]
        private readonly bool $beaconEnabled,
        #[Autowire('%nowo.beacon.dsn%')]
        private readonly ?string $beaconDsn,
        #[Autowire('%nowo.beacon.environment%')]
        private readonly string $beaconEnvironment,
        #[Autowire('%nowo.beacon.release%')]
        private readonly ?string $beaconRelease = null,
    ) {
    }

    /**
     * Demo home with links to capture scenarios.
     */
    #[Route(path: '/', name: 'homepage', methods: ['GET'])]
    public function home(): Response
    {
        return $this->render('demo/home.html.twig');
    }

    /**
     * Capture an info-level message.
     */
    #[Route(path: '/report', name: 'demo_report', methods: ['GET'])]
    public function report(BeaconClientInterface $beacon, Request $request): Response
    {
        $eventId = $beacon->captureMessage(
            'Beacon demo message',
            'info',
            $this->richExtra('demo_report', $request, [
                'note' => 'Minimal message path; send.* still attaches env/release/runtime/request/stacktrace.',
            ]),
        );

        return $this->renderReport(
            heading: 'Beacon info report',
            description: 'Captured a demo message with level "info" (plus default send.* contexts).',
            enabled: $beacon->isEnabled(),
            eventId: $eventId,
            level: 'info',
        );
    }

    /**
     * Capture an error-level message.
     */
    #[Route(path: '/report-error', name: 'demo_report_error', methods: ['GET'])]
    public function reportError(BeaconClientInterface $beacon, Request $request): Response
    {
        $eventId = $beacon->captureMessage(
            'Beacon demo error message',
            'error',
            $this->richExtra('demo_report_error', $request, [
                'severity' => 'error',
                'ops' => ['page_oncall' => true, 'slack_channel' => '#beacon-demo'],
            ]),
        );

        return $this->renderReport(
            heading: 'Beacon error report',
            description: 'Captured a demo message with level "error".',
            enabled: $beacon->isEnabled(),
            eventId: $eventId,
            level: 'error',
        );
    }

    /**
     * Manually capture a RuntimeException (with previous exception chain).
     */
    #[Route(path: '/exception', name: 'demo_exception', methods: ['GET'])]
    public function exception(BeaconClientInterface $beacon, Request $request): Response
    {
        $previous = new InvalidArgumentException('Beacon demo nested cause (not ignored on manual capture).');
        $exception = new RuntimeException('Beacon demo manual exception.', 0, $previous);
        $eventId = $beacon->captureException(
            $exception,
            $this->richExtra('demo_exception', $request, [
                'checkout' => $this->sampleCheckoutContext(),
                'note' => 'Manual captureException ignores ignore_exceptions (that list applies to the HTTP listener only).',
            ]),
        );

        return $this->renderReport(
            heading: 'Manual exception capture',
            description: 'Created a RuntimeException (with previous) and sent it with captureException().',
            enabled: $beacon->isEnabled(),
            eventId: $eventId,
            level: 'error',
        );
    }

    /**
     * Throw an uncaught exception for the automatic HTTP listener.
     */
    #[Route(path: '/boom', name: 'demo_boom', methods: ['GET'])]
    public function boom(): never
    {
        throw new RuntimeException('Beacon demo listener exception.');
    }

    /**
     * Throw an ignored InvalidArgumentException (500 without Beacon ingest).
     */
    #[Route(path: '/boom-ignored', name: 'demo_boom_ignored', methods: ['GET'])]
    public function boomIgnored(): never
    {
        throw new InvalidArgumentException('Beacon demo ignored exception.');
    }

    /**
     * Capture a message with a custom fingerprint and breadcrumbs.
     */
    #[Route(path: '/fingerprint', name: 'demo_fingerprint', methods: ['GET'])]
    public function fingerprint(BeaconClientInterface $beacon, Request $request): Response
    {
        $beacon->addBreadcrumb('Opened fingerprint demo', 'navigation', 'info', [
            'path' => $request->getPathInfo(),
        ]);
        $beacon->addBreadcrumb('Custom grouping sample', 'demo', 'info', [
            'group' => 'group-1',
            'reason' => 'stable fingerprint across identical demos',
        ]);

        $fingerprint = ['demo', 'fingerprint', 'group-1'];
        $eventId = $beacon->captureMessage(
            'Beacon demo fingerprinted message',
            'error',
            $this->richExtra('demo_fingerprint', $request, [
                'note' => 'Message events include current stacktrace + request when send.* defaults are on.',
                'grouping' => ['strategy' => 'custom_fingerprint', 'values' => $fingerprint],
            ]),
            $fingerprint,
        );

        return $this->renderReport(
            heading: 'Fingerprint demo',
            description: 'Captured a message with custom fingerprint, breadcrumbs, request context, and current stacktrace (no Throwable).',
            enabled: $beacon->isEnabled(),
            eventId: $eventId,
            level: 'error',
            fingerprint: $fingerprint,
        );
    }

    /**
     * Attach breadcrumbs then capture a message.
     */
    #[Route(path: '/breadcrumbs', name: 'demo_breadcrumbs', methods: ['GET'])]
    public function breadcrumbs(BeaconClientInterface $beacon, Request $request): Response
    {
        $beacon->addBreadcrumb('Opened demo home', 'navigation', 'info');
        $beacon->addBreadcrumb('Clicked breadcrumbs demo', 'ui', 'info', ['route' => 'demo_breadcrumbs']);
        $beacon->addBreadcrumb('Loaded cart preview', 'query', 'info', [
            'sql' => 'SELECT id, sku FROM cart_item WHERE cart_id = ?',
            'row_count' => 3,
        ]);
        $eventId = $beacon->captureMessage(
            'Beacon demo with breadcrumbs',
            'info',
            $this->richExtra('demo_breadcrumbs', $request),
        );

        return $this->renderReport(
            heading: 'Breadcrumbs demo',
            description: 'Three breadcrumbs were recorded and attached to this event (cleared after send).',
            enabled: $beacon->isEnabled(),
            eventId: $eventId,
            level: 'info',
        );
    }

    /**
     * Capture a message that may include authenticated user context (`send.user`).
     */
    #[Route(path: '/user', name: 'demo_user', methods: ['GET'])]
    public function userContext(BeaconClientInterface $beacon, Request $request): Response
    {
        $eventId = $beacon->captureMessage(
            'Beacon demo user context',
            'info',
            $this->richExtra('demo_user', $request, [
                'authenticated' => null !== $this->getUser(),
                'hint' => 'Log in at /login (debugger/debug) so payload.user is populated when send.user is true.',
            ]),
        );

        return $this->renderReport(
            heading: 'User context demo',
            description: $this->getUser()
                ? 'send.user is enabled in demos: the authenticated user summary is attached when present.'
                : 'No authenticated user. Log in via /login then reopen this route to attach user context.',
            enabled: $beacon->isEnabled(),
            eventId: $eventId,
            level: 'info',
        );
    }

    /**
     * Richest single event: breadcrumbs, nested exception, fingerprint, and dense extra.
     */
    #[Route(path: '/full-context', name: 'demo_full_context', methods: ['GET'])]
    public function fullContext(BeaconClientInterface $beacon, Request $request): Response
    {
        $beacon->addBreadcrumb('Session started', 'auth', 'info', ['provider' => 'demo_memory']);
        $beacon->addBreadcrumb('Opened checkout', 'navigation', 'info', ['step' => 'payment']);
        $beacon->addBreadcrumb('Payment provider timeout', 'http', 'warning', [
            'url' => 'https://payments.example.test/charge',
            'status_code' => 504,
            'duration_ms' => 3201,
        ]);
        $beacon->addBreadcrumb('Retry scheduled', 'queue', 'info', ['delay_sec' => 5]);

        $previous = new RuntimeException('Upstream payment gateway timed out.');
        $exception = new RuntimeException('Beacon demo full-context exception (checkout failed).', 402, $previous);

        $fingerprint = ['demo', 'full-context', 'checkout'];
        $eventId = $beacon->captureException(
            $exception,
            $this->richExtra('demo_full_context', $request, [
                'checkout' => $this->sampleCheckoutContext(),
                'customer' => [
                    'segment' => 'enterprise',
                    'locale' => 'en_US',
                    'plan' => 'pro',
                ],
                'feature_flags' => [
                    'new_checkout' => true,
                    'beacon_dense_extra' => true,
                ],
                'tags_like' => [
                    'module' => 'checkout',
                    'team' => 'payments',
                    'severity' => 'P1',
                ],
            ]),
            $fingerprint,
        );

        return $this->renderReport(
            heading: 'Full context demo',
            description: 'Exception + previous, four breadcrumbs, custom fingerprint, and dense nested extra. With send.* on, also request / user / runtime / framework / os / release / server_name / stack source context.',
            enabled: $beacon->isEnabled(),
            eventId: $eventId,
            level: 'error',
            fingerprint: $fingerprint,
        );
    }

    /**
     * Capture a performance transaction with sample spans (not enough repeats for N+1).
     */
    #[Route(path: '/transaction', name: 'demo_transaction', methods: ['GET'])]
    public function transaction(BeaconClientInterface $beacon, Request $request): Response
    {
        $start = microtime(true);
        usleep(12000);
        $mid = microtime(true);
        usleep(8000);
        $end = microtime(true);

        $eventId = $beacon->captureTransaction(
            'demo.checkout',
            $start,
            $end,
            [
                [
                    'op' => 'demo.work',
                    'description' => 'simulate checkout step',
                    'span_id' => bin2hex(random_bytes(8)),
                    'start_timestamp' => $start,
                    'timestamp' => $mid,
                ],
                [
                    'op' => 'db.query',
                    'description' => 'SELECT demo',
                    'span_id' => bin2hex(random_bytes(8)),
                    'start_timestamp' => $mid,
                    'timestamp' => $end,
                ],
                [
                    'op' => 'http.client',
                    'description' => 'GET https://api.example.test/rates',
                    'span_id' => bin2hex(random_bytes(8)),
                    'start_timestamp' => $mid,
                    'timestamp' => $end,
                ],
            ],
            $this->richExtra('demo_transaction', $request, [
                'checkout' => $this->sampleCheckoutContext(),
            ]),
        );

        return $this->renderReport(
            heading: 'Performance transaction',
            description: 'Sent a transaction envelope with three spans. Check Beacon → Performance. For N+1 detection use /transaction-nplus1. With auto_http_transaction, this page also emits a request-level transaction on terminate.',
            enabled: $beacon->isEnabled(),
            eventId: $eventId,
            level: 'info',
        );
    }

    /**
     * Capture a transaction with ≥5 similar DB spans so Beacon flags an N+1 group.
     */
    #[Route(path: '/transaction-nplus1', name: 'demo_transaction_nplus1', methods: ['GET'])]
    public function transactionNPlusOne(BeaconClientInterface $beacon, Request $request): Response
    {
        $start = microtime(true);
        $spans = [];
        $cursor = $start;
        for ($i = 1; $i <= 6; ++$i) {
            usleep(2000);
            $next = microtime(true);
            $spans[] = [
                'op' => 'db.sql.query',
                'description' => \sprintf('SELECT * FROM product WHERE id = %d', $i),
                'span_id' => bin2hex(random_bytes(8)),
                'start_timestamp' => $cursor,
                'timestamp' => $next,
            ];
            $cursor = $next;
        }
        $end = microtime(true);

        $eventId = $beacon->captureTransaction(
            'demo.nplus1.products',
            $start,
            $end,
            $spans,
            $this->richExtra('demo_transaction_nplus1', $request, [
                'nplus1' => true,
                'repeat_count' => 6,
            ]),
        );

        return $this->renderReport(
            heading: 'N+1 performance transaction',
            description: 'Sent 6 similar db.sql.query spans. Beacon marks an N+1 group when ≥5 repeats match after normalizing IDs. Open Beacon → Performance → filter “N+1 only”.',
            enabled: $beacon->isEnabled(),
            eventId: $eventId,
            level: 'info',
        );
    }

    /**
     * Simulate a final Messenger worker failure (same payload as BeaconMessengerFailedListener).
     *
     * Dispatching {@see WorkerMessageFailedEvent} on the app EventDispatcher also runs Symfony's
     * retry listeners (which expect a real worker). This route mirrors the Beacon listener output.
     */
    #[Route(path: '/messenger-fail', name: 'demo_messenger_fail', methods: ['GET'])]
    public function messengerFail(BeaconClientInterface $beacon, Request $request): Response
    {
        $message = new DemoFailMessage(reason: 'payment-timeout', attempt: 3);
        $throwable = new RuntimeException('Beacon demo messenger final failure.');
        $eventId = $beacon->captureException(
            $throwable,
            $this->richExtra('demo_messenger_fail', $request, [
                'messenger' => [
                    'message_class' => $message::class,
                    'receiver_name' => 'sync',
                    'simulated' => true,
                    'note' => 'Same extra keys as BeaconMessengerFailedListener on WorkerMessageFailedEvent when willRetry() is false.',
                ],
            ]),
        );

        return $this->renderReport(
            heading: 'Messenger failure demo',
            description: 'Sent captureException with extra.messenger.message_class / receiver_name (same shape as BeaconMessengerFailedListener). In production workers, that listener runs on final WorkerMessageFailedEvent.',
            enabled: $beacon->isEnabled(),
            eventId: $eventId,
            level: 'error',
        );
    }

    /**
     * Document auto HTTP transactions (enabled in nowo_beacon.yaml for this demo).
     */
    #[Route(path: '/auto-http', name: 'demo_auto_http', methods: ['GET'])]
    public function autoHttp(BeaconClientInterface $beacon, Request $request): Response
    {
        $eventId = $beacon->captureMessage(
            'Beacon demo auto_http_transaction companion message',
            'info',
            $this->richExtra('demo_auto_http', $request, [
                'note' => 'On kernel.terminate, BeaconRequestTransactionListener also sends a transaction named after this route.',
            ]),
        );

        return $this->renderReport(
            heading: 'Auto HTTP transaction',
            description: 'Message sent now; when the response finishes, auto_http_transaction also posts a performance transaction for this request (skipped for profiler / WDT / health / build).',
            enabled: $beacon->isEnabled(),
            eventId: $eventId,
            level: 'info',
        );
    }

    /**
     * Log an error through Monolog so BeaconMonologHandler can forward it.
     */
    #[Route(path: '/monolog', name: 'demo_monolog', methods: ['GET'])]
    public function monolog(\Psr\Log\LoggerInterface $logger, BeaconClientInterface $beacon, Request $request): Response
    {
        $logger->error('Beacon demo Monolog error', $this->richExtra('demo_monolog', $request, [
            'channel_hint' => 'forwarded when monolog_handler.enabled is true',
        ]));

        return $this->renderReport(
            heading: 'Monolog demo',
            description: $beacon->isEnabled()
                ? 'Logged an error via Monolog; BeaconMonologHandler forwards it when monolog_handler.enabled is true.'
                : 'Beacon client is disabled; Monolog still logged locally.',
            enabled: $beacon->isEnabled(),
            eventId: null,
            level: 'error',
        );
    }

    /**
     * JSON runtime status for the wired Beacon client.
     */
    #[Route(path: '/status', name: 'demo_status', methods: ['GET'])]
    public function status(BeaconClientInterface $beacon): JsonResponse
    {
        $dsn = trim((string) $this->beaconDsn);
        $hasSecret = false;
        if ('' !== $dsn && preg_match('#^[^:]+://[^:]+:[^@]+@#', $dsn)) {
            $hasSecret = true;
        }

        return $this->json([
            'enabled' => $beacon->isEnabled(),
            'has_dsn' => '' !== $dsn,
            'has_secret_in_dsn' => $hasSecret,
            'environment' => $this->beaconEnvironment,
            'release' => $this->beaconRelease,
            'auto_http_transaction' => true,
            'auth' => 'X-Beacon-Auth beacon_key + beacon_secret (required)',
        ]);
    }

    /**
     * Dense nested checkout sample used by full-context / transaction demos.
     *
     * @return array<string, mixed>
     */
    private function sampleCheckoutContext(): array
    {
        return [
            'cart_id' => 'cart_demo_42',
            'currency' => 'EUR',
            'total_cents' => 12999,
            'items' => [
                ['sku' => 'SKU-100', 'qty' => 2, 'unit_cents' => 4999],
                ['sku' => 'SKU-200', 'qty' => 1, 'unit_cents' => 3001],
            ],
            'shipping' => [
                'country' => 'ES',
                'method' => 'standard',
            ],
        ];
    }

    /**
     * Shared extra payload for demos (merged with route-specific keys).
     *
     * @param array<string, mixed> $more
     *
     * @return array<string, mixed>
     */
    private function richExtra(string $route, Request $request, array $more = []): array
    {
        return array_merge([
            'demo' => true,
            'route' => $route,
            'bundle' => 'nowo-tech/beacon-bundle',
            'client' => [
                'user_agent_prefix' => 'beacon-bundle/1.5',
                'content_type' => 'application/x-beacon-envelope',
                'auth_header' => 'X-Beacon-Auth',
            ],
            'http' => [
                'method' => $request->getMethod(),
                'path' => $request->getPathInfo(),
                'query' => $request->query->all(),
            ],
            'php' => [
                'version' => \PHP_VERSION,
                'sapi' => \PHP_SAPI,
            ],
        ], $more);
    }

    /**
     * Render a shared Twig report page for demo captures.
     *
     * @param list<string>|null $fingerprint
     */
    private function renderReport(
        string $heading,
        string $description,
        bool $enabled,
        ?string $eventId,
        string $level,
        ?array $fingerprint = null,
        ?string $note = null,
    ): Response {
        return $this->render('demo/report.html.twig', [
            'heading' => $heading,
            'description' => $description,
            'enabled' => $enabled,
            'eventId' => $eventId,
            'level' => $level,
            'fingerprint' => $fingerprint,
            'note' => $note,
        ]);
    }
}
