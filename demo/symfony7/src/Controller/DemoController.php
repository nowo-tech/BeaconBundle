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

final class DemoController extends AbstractController
{
    public function __construct(
        #[Autowire('%nowo.beacon.enabled%')]
        private readonly bool $beaconEnabled,
        #[Autowire('%nowo.beacon.dsn%')]
        private readonly string $beaconDsn,
        #[Autowire('%nowo.beacon.environment%')]
        private readonly string $beaconEnvironment,
    ) {
    }

    #[Route(path: '/', name: 'homepage', methods: ['GET'])]
    public function home(): Response
    {
        return $this->render('demo/home.html.twig');
    }

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

    #[Route(path: '/boom', name: 'demo_boom', methods: ['GET'])]
    public function boom(): never
    {
        throw new \RuntimeException('Beacon demo listener exception.');
    }

    #[Route(path: '/boom-ignored', name: 'demo_boom_ignored', methods: ['GET'])]
    public function boomIgnored(): never
    {
        throw new InvalidArgumentException('Beacon demo ignored exception.');
    }

    #[Route(path: '/fingerprint', name: 'demo_fingerprint', methods: ['GET'])]
    public function fingerprint(BeaconClientInterface $beacon): Response
    {
        $eventId = $beacon->captureMessage(
            'Beacon demo fingerprinted message',
            'error',
            [
                'demo' => true,
                'route' => 'demo_fingerprint',
            ],
            [
                'demo',
                'fingerprint',
                'group-1',
            ],
        );

        return $this->renderReport(
            heading: 'Fingerprint demo',
            description: 'Captured a message with a custom fingerprint for grouping.',
            enabled: $beacon->isEnabled(),
            eventId: $eventId,
            level: 'error',
            fingerprint: ['demo', 'fingerprint', 'group-1'],
        );
    }

    #[Route(path: '/status', name: 'demo_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return $this->json([
            'enabled' => $this->beaconEnabled,
            'has_dsn' => '' !== $this->beaconDsn,
            'environment' => $this->beaconEnvironment,
        ]);
    }

    /**
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
