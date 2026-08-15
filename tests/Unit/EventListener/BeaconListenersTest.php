<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\EventListener;

use DateTimeImmutable;
use Nowo\BeaconBundle\Client\BeaconClientInterface;
use Nowo\BeaconBundle\EventListener\BeaconMessengerFailedListener;
use Nowo\BeaconBundle\EventListener\BeaconRequestTransactionListener;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Stringable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;
use Symfony\Component\Scheduler\Trigger\TriggerInterface;

use function is_array;

use const DATE_ATOM;

final class BeaconListenersTest extends TestCase
{
    public function testMessengerListenerReportsOnlyFinalFailures(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::once())->method('captureException')->with(
            self::isInstanceOf(RuntimeException::class),
            self::callback(static function (array $extra): bool {
                return ($extra['messenger']['message_class'] ?? null) === stdClass::class
                    && ($extra['messenger']['receiver_name'] ?? null) === 'async'
                    && ($extra['messenger']['retry_count'] ?? null) === 0
                    && !isset($extra['scheduler']);
            }),
        );

        $listener = new BeaconMessengerFailedListener($client, true, []);
        $envelope = new Envelope(new stdClass());
        $event    = new WorkerMessageFailedEvent($envelope, 'async', new RuntimeException('boom'));
        // Default willRetry is false when not set for retry — ensure final failure path
        $listener($event);
    }

    public function testMessengerListenerAttachesSchedulerContextFromStamp(): void
    {
        $triggeredAt = new DateTimeImmutable('2026-07-31T10:00:00+00:00');
        $trigger     = new class implements TriggerInterface, Stringable {
            public function __toString(): string
            {
                return '0 * * * *';
            }

            public function getNextRunDate(DateTimeImmutable $run): DateTimeImmutable
            {
                return $run;
            }
        };

        $context  = new MessageContext('default', 'cleanup-old', $trigger, $triggeredAt);
        $envelope = new Envelope(new stdClass(), [new ScheduledStamp($context)]);

        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::once())->method('captureException')->with(
            self::anything(),
            self::callback(static function (array $extra) use ($triggeredAt): bool {
                $scheduler = $extra['scheduler'] ?? null;

                return is_array($scheduler)
                    && $scheduler['schedule_name'] === 'default'
                    && $scheduler['recurring_id'] === 'cleanup-old'
                    && $scheduler['triggered_at'] === $triggeredAt->format(DATE_ATOM)
                    && $scheduler['trigger'] === '0 * * * *'
                    && !isset($extra['scheduler']['message']);
            }),
        );

        $listener = new BeaconMessengerFailedListener($client, true, [], true);
        $listener(new WorkerMessageFailedEvent($envelope, 'scheduler_default', new RuntimeException('scheduled boom')));
    }

    public function testMessengerListenerOmitsSchedulerContextWhenDisabled(): void
    {
        $trigger = new class implements TriggerInterface {
            public function __toString(): string
            {
                return '@hourly';
            }

            public function getNextRunDate(DateTimeImmutable $run): DateTimeImmutable
            {
                return $run;
            }
        };
        $context  = new MessageContext('default', 'id-1', $trigger, new DateTimeImmutable());
        $envelope = new Envelope(new stdClass(), [new ScheduledStamp($context)]);

        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::once())->method('captureException')->with(
            self::anything(),
            self::callback(static function (array $extra): bool {
                return isset($extra['messenger']) && !isset($extra['scheduler']);
            }),
        );

        $listener = new BeaconMessengerFailedListener($client, true, [], false);
        $listener(new WorkerMessageFailedEvent($envelope, 'async', new RuntimeException('boom')));
    }

    public function testMessengerListenerSkipsWhenDisabledOrRetryingOrIgnored(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::never())->method('captureException');

        $envelope = new Envelope(new stdClass());

        $disabled = new BeaconMessengerFailedListener($client, false, []);
        $disabled(new WorkerMessageFailedEvent($envelope, 'async', new RuntimeException('x')));

        $retryEvent = new WorkerMessageFailedEvent($envelope, 'async', new RuntimeException('x'));
        $retryEvent->setForRetry();
        (new BeaconMessengerFailedListener($client, true, []))($retryEvent);

        $ignored = new BeaconMessengerFailedListener($client, true, [RuntimeException::class]);
        $ignored(new WorkerMessageFailedEvent($envelope, 'async', new RuntimeException('ignored')));

        $clientReports = $this->createMock(BeaconClientInterface::class);
        $clientReports->method('isEnabled')->willReturn(true);
        $clientReports->expects(self::once())->method('captureException');
        /** @var list<mixed> $ignore */
        $ignore = ['', 123];
        (new BeaconMessengerFailedListener($clientReports, true, $ignore))(
            new WorkerMessageFailedEvent($envelope, 'async', new RuntimeException('report')),
        );
    }

    public function testMessengerListenerSkipsWhenClientDisabled(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(false);
        $client->expects(self::never())->method('captureException');

        $listener = new BeaconMessengerFailedListener($client, true, []);
        $listener(new WorkerMessageFailedEvent(new Envelope(new stdClass()), 'async', new RuntimeException('x')));
    }

    public function testRequestTransactionListenerCapturesOnTerminate(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::once())->method('captureTransaction');

        $listener = new BeaconRequestTransactionListener($client, true);
        $kernel   = $this->createMock(HttpKernelInterface::class);
        $request  = Request::create('/dashboard');
        $request->attributes->set('_route', 'dashboard_home');

        self::assertArrayHasKey(KernelEvents::REQUEST, BeaconRequestTransactionListener::getSubscribedEvents());
        self::assertArrayHasKey(KernelEvents::TERMINATE, BeaconRequestTransactionListener::getSubscribedEvents());

        $listener->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $listener->onKernelTerminate(new TerminateEvent($kernel, $request, new Response('ok', 200)));
    }

    public function testRequestTransactionListenerUsesMethodPathWhenRouteMissing(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::once())->method('captureTransaction')->with(
            'GET /api/items',
            self::anything(),
            self::anything(),
            [],
            self::isType('array'),
        );

        $listener = new BeaconRequestTransactionListener($client, true);
        $kernel   = $this->createMock(HttpKernelInterface::class);
        $request  = Request::create('/api/items');

        $listener->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $listener->onKernelTerminate(new TerminateEvent($kernel, $request, new Response('ok', 200)));
    }

    public function testRequestTransactionListenerSkipsWhenDisabled(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->expects(self::never())->method('captureTransaction');

        $listener = new BeaconRequestTransactionListener($client, false);
        $kernel   = $this->createMock(HttpKernelInterface::class);
        $request  = Request::create('/dashboard');

        $listener->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $listener->onKernelTerminate(new TerminateEvent($kernel, $request, new Response('ok', 200)));
    }

    public function testRequestTransactionListenerSkipsHealth(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::never())->method('captureTransaction');

        $listener = new BeaconRequestTransactionListener($client, true);
        $kernel   = $this->createMock(HttpKernelInterface::class);
        $request  = Request::create('/health/live');

        $listener->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $listener->onKernelTerminate(new TerminateEvent($kernel, $request, new Response('ok', 200)));
    }

    public function testRequestTransactionListenerSkipsChromeDevtoolsProbe(): void
    {
        $client = $this->createMock(BeaconClientInterface::class);
        $client->method('isEnabled')->willReturn(true);
        $client->expects(self::never())->method('captureTransaction');

        $listener = new BeaconRequestTransactionListener($client, true);
        $kernel   = $this->createMock(HttpKernelInterface::class);
        $request  = Request::create('/.well-known/appspecific/com.chrome.devtools.json/');

        $listener->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
        $listener->onKernelTerminate(new TerminateEvent($kernel, $request, new Response('ok', 200)));
    }
}
