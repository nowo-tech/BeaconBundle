<?php

declare(strict_types=1);

namespace App\Controller;

use InvalidArgumentException;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
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
    public function report(BeaconClientInterface $beacon): Response
    {
        $eventId = $beacon->captureMessage('Beacon demo message', 'info', [
            'demo' => true,
            'route' => 'demo_report',
        ]);

        return $this->renderReport(
            heading: 'Beacon info report',
            description: 'Captured a demo message with level "info".',
            enabled: $beacon->isEnabled(),
            eventId: $eventId,
            level: 'info',
        );
    }

    /**
     * Capture an error-level message.
     */
    #[Route(path: '/report-error', name: 'demo_report_error', methods: ['GET'])]
    public function reportError(BeaconClientInterface $beacon): Response
    {
        $eventId = $beacon->captureMessage('Beacon demo error message', 'error', [
            'demo' => true,
            'route' => 'demo_report_error',
        ]);

        return $this->renderReport(
            heading: 'Beacon error report',
            description: 'Captured a demo message with level "error".',
            enabled: $beacon->isEnabled(),
            eventId: $eventId,
            level: 'error',
        );
    }

    /**
     * Manually capture a RuntimeException.
     */
    #[Route(path: '/exception', name: 'demo_exception', methods: ['GET'])]
    public function exception(BeaconClientInterface $beacon): Response
    {
        $exception = new \RuntimeException('Beacon demo manual exception.');
        $eventId = $beacon->captureException($exception, [
            'demo' => true,
            'route' => 'demo_exception',
        ]);

        return $this->renderReport(
            heading: 'Manual exception capture',
            description: 'Created a RuntimeException and sent it with captureException().',
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
        throw new \RuntimeException('Beacon demo listener exception.');
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
    public function fingerprint(BeaconClientInterface $beacon): Response
    {
        $beacon->addBreadcrumb('Opened fingerprint demo', 'navigation', 'info');
        $beacon->addBreadcrumb('Custom grouping sample', 'demo', 'info', ['group' => 'group-1']);

        $eventId = $beacon->captureMessage(
            'Beacon demo fingerprinted message',
            'error',
            [
                'demo' => true,
                'route' => 'demo_fingerprint',
                'note' => 'Message events include current stacktrace + request when send.* defaults are on.',
            ],
            [
                'demo',
                'fingerprint',
                'group-1',
            ],
        );

        return $this->renderReport(
            heading: 'Fingerprint demo',
            description: 'Captured a message with custom fingerprint, breadcrumbs, request context, and current stacktrace (no Throwable).',
            enabled: $beacon->isEnabled(),
            eventId: $eventId,
            level: 'error',
            fingerprint: ['demo', 'fingerprint', 'group-1'],
        );
    }

    /**
     * Attach breadcrumbs then capture a message.
     */
    #[Route(path: '/breadcrumbs', name: 'demo_breadcrumbs', methods: ['GET'])]
    public function breadcrumbs(BeaconClientInterface $beacon): Response
    {
        $beacon->addBreadcrumb('Opened demo home', 'navigation', 'info');
        $beacon->addBreadcrumb('Clicked breadcrumbs demo', 'ui', 'info', ['route' => 'demo_breadcrumbs']);
        $eventId = $beacon->captureMessage('Beacon demo with breadcrumbs', 'info', [
            'demo' => true,
            'route' => 'demo_breadcrumbs',
        ]);

        return $this->renderReport(
            heading: 'Breadcrumbs demo',
            description: 'Two breadcrumbs were recorded and attached to this event (cleared after send).',
            enabled: $beacon->isEnabled(),
            eventId: $eventId,
            level: 'info',
        );
    }

    /**
     * Capture a message that may include authenticated user context (`send.user`).
     */
    #[Route(path: '/user', name: 'demo_user', methods: ['GET'])]
    public function userContext(BeaconClientInterface $beacon): Response
    {
        $eventId = $beacon->captureMessage('Beacon demo user context', 'info', [
            'demo' => true,
            'route' => 'demo_user',
            'authenticated' => $this->getUser() !== null,
        ]);

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
     * Capture a performance transaction with sample spans.
     */
    #[Route(path: '/transaction', name: 'demo_transaction', methods: ['GET'])]
    public function transaction(BeaconClientInterface $beacon): Response
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
            ],
            ['demo' => true, 'route' => 'demo_transaction'],
        );

        return $this->renderReport(
            heading: 'Performance transaction',
            description: 'Sent a transaction envelope with two spans. Check Beacon → Performance.',
            enabled: $beacon->isEnabled(),
            eventId: $eventId,
            level: 'info',
        );
    }

    /**
     * Log an error through Monolog so BeaconMonologHandler can forward it.
     */
    #[Route(path: '/monolog', name: 'demo_monolog', methods: ['GET'])]
    public function monolog(\Psr\Log\LoggerInterface $logger, BeaconClientInterface $beacon): Response
    {
        $logger->error('Beacon demo Monolog error', ['demo' => true, 'route' => 'demo_monolog']);

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
        return $this->json([
            'enabled' => $beacon->isEnabled(),
            'has_dsn' => '' !== trim((string) $this->beaconDsn),
            'environment' => $this->beaconEnvironment,
        ]);
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
    ): Response {
        return $this->render('demo/report.html.twig', [
            'heading' => $heading,
            'description' => $description,
            'enabled' => $enabled,
            'eventId' => $eventId,
            'level' => $level,
            'fingerprint' => $fingerprint,
        ]);
    }
}
