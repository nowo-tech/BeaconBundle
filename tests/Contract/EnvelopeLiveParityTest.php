<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Contract;

use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\EnvelopeBuilder;
use Nowo\BeaconBundle\Envelope\SendOptions;
use Nowo\BeaconBundle\Scope\Scope;
use Nowo\BeaconBundle\Tests\Fixtures\Golden\GoldenCases;
use Nowo\BeaconBundle\Tests\Fixtures\Golden\Legacy\LegacyEnvelopeBuilder;
use Nowo\BeaconBundle\Trace\TraceIdProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope as MessengerEnvelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

use function array_map;
use function assert;
use function count;
use function json_encode;

/**
 * Environment-dependent envelopes (PHP version, OS release, real source lines, real frame arguments, the current
 * stack) compared in-process with the frozen 1.20.0 builder: identical except the header `dsn`.
 */
final class EnvelopeLiveParityTest extends TestCase
{
    public function testRealExceptionWithRuntimeOsFrameworkSourceContextAndFrameVars(): void
    {
        $throwable = $this->failWith(['password' => 'hunter2', 'sessionToken' => 'x', 'id' => 7], 'plain-arg');
        $send      = new SendOptions(stackVars: true);

        [$current, $legacy] = $this->builders($send, stackContextLines: 3);
        $dsn                = $this->dsn();

        $body = $current->buildEventEnvelope($dsn, '', 'error', $throwable, ['k' => 'v']);
        self::assertStringContainsString('"pre_context"', $body);
        self::assertStringContainsString('"vars"', $body);
        self::assertStringContainsString('"runtime"', $body);
        self::assertStringContainsString('"framework":{"name":"symfony"', $body);
        self::assertSame(
            GoldenCases::withoutHeaderDsn(GoldenCases::normalize($legacy->buildEventEnvelope($dsn, '', 'error', $throwable, ['k' => 'v']))),
            GoldenCases::normalize($body),
        );
    }

    public function testMessageWithCurrentStacktraceAndStackVars(): void
    {
        [$current, $legacy] = $this->builders(new SendOptions(stackVars: true));
        $dsn                = $this->dsn();

        $currentBody = $this->captureFrom($current, $dsn, 'apiToken=abc123456 from the stack');
        $legacyBody  = $this->captureFrom($legacy, $dsn, 'apiToken=abc123456 from the stack');

        self::assertStringContainsString('"stacktrace":{"frames":[', $currentBody);
        self::assertStringContainsString('"function":"array_map"', $currentBody);
        self::assertStringContainsString('"sessionId":"[Filtered]"', $currentBody);
        self::assertSame(GoldenCases::withoutHeaderDsn(GoldenCases::normalize($legacyBody)), GoldenCases::normalize($currentBody));
    }

    public function testMessageWithCurrentStacktraceWithoutStackVars(): void
    {
        [$current, $legacy] = $this->builders(new SendOptions());
        $dsn                = $this->dsn();

        self::assertSame(
            GoldenCases::withoutHeaderDsn(GoldenCases::normalize($this->captureFrom($legacy, $dsn, 'plain'))),
            GoldenCases::normalize($this->captureFrom($current, $dsn, 'plain')),
        );
    }

    public function testMessengerHandlerFailedCulprit(): void
    {
        $root    = $this->failWith([], 'root');
        $wrapper = new HandlerFailedException(new MessengerEnvelope(new stdClass()), [$root]);

        [$current, $legacy] = $this->builders(new SendOptions(runtime: false, os: false));
        $dsn                = $this->dsn();

        $body = $current->buildEventEnvelope($dsn, '', 'error', $wrapper);
        self::assertStringContainsString('"culprit":' . json_encode(self::class . '->failWith'), $body);
        self::assertSame(
            GoldenCases::withoutHeaderDsn(GoldenCases::normalize($legacy->buildEventEnvelope($dsn, '', 'error', $wrapper))),
            GoldenCases::normalize($body),
        );
    }

    public function testRequestWithArrayControllerIntegerQueryKeysAndClient(): void
    {
        $request = Request::create('https://shop.example/p?0=a&token=t&list[]=1', 'GET', [], [], [], [
            'HTTP_REFERER'    => 'https://shop.example/?password=x',
            'HTTP_USER_AGENT' => 'UA/1',
            'REMOTE_ADDR'     => '198.51.100.7',
        ]);
        $request->attributes->set('_controller', [new stdClass(), 'show']);
        $request->attributes->set('_route', 'product_show');

        $send               = new SendOptions(stacktrace: false, runtime: false, os: false, client: true);
        [$current, $legacy] = $this->builders($send, new RequestStack([$request]));
        $dsn                = $this->dsn();

        $body = $current->buildEventEnvelope($dsn, 'with request', 'error');
        self::assertStringContainsString('"controller":"stdClass::show"', $body);
        self::assertStringContainsString('"query_keys":[0,"token","list"]', $body);
        self::assertSame(
            GoldenCases::withoutHeaderDsn(GoldenCases::normalize($legacy->buildEventEnvelope($dsn, 'with request', 'error'))),
            GoldenCases::normalize($body),
        );
    }

    public function testRequestStackWithoutCurrentRequest(): void
    {
        [$current, $legacy] = $this->builders(new SendOptions(stacktrace: false, runtime: false, os: false), new RequestStack());
        $dsn                = $this->dsn();

        self::assertSame(
            GoldenCases::withoutHeaderDsn(GoldenCases::normalize($legacy->buildTransactionEnvelope($dsn, 'cli', 1.0, 2.0))),
            GoldenCases::normalize($current->buildTransactionEnvelope($dsn, 'cli', 1.0, 2.0)),
        );
    }

    public function testInvalidUtf8StillThrows(): void
    {
        [$current] = $this->builders(new SendOptions(stacktrace: false));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to encode envelope JSON: Malformed UTF-8');
        $current->buildEventEnvelope($this->dsn(), "bad \xB1\x31 bytes", 'error');
    }

    /**
     * @return array{0: EnvelopeBuilder, 1: LegacyEnvelopeBuilder}
     */
    private function builders(SendOptions $send, ?RequestStack $stack = null, int $stackContextLines = 5): array
    {
        $make = static function (string $class) use ($send, $stack, $stackContextLines): EnvelopeBuilder|LegacyEnvelopeBuilder {
            $trace = new TraceIdProvider();
            $trace->set(GoldenCases::TRACE_ID);

            $builder = new $class(
                environment: 'prod',
                serverName: 'host',
                sendOptions: $send,
                requestStack: $stack,
                stackContextLines: $stackContextLines,
                scope: new Scope(),
                traceIdProvider: $trace,
                clock: new MockClock(GoldenCases::CLOCK),
            );
            assert($builder instanceof EnvelopeBuilder || $builder instanceof LegacyEnvelopeBuilder);

            return $builder;
        };

        /** @var EnvelopeBuilder $current */
        $current = $make(EnvelopeBuilder::class);
        /** @var LegacyEnvelopeBuilder $legacy */
        $legacy = $make(LegacyEnvelopeBuilder::class);

        return [$current, $legacy];
    }

    private function dsn(): BeaconDsn
    {
        return (new BeaconDsnParser())->parse(GoldenCases::DSN);
    }

    /**
     * Same call site for both builders. `array_map()` is an application-side frame (no bundle class, not under
     * `src/`), so its arguments become frame `vars`; `sessionId` is only filtered by the 1.19.2 key rules.
     */
    private function captureFrom(EnvelopeBuilder|LegacyEnvelopeBuilder $builder, BeaconDsn $dsn, string $message): string
    {
        $bodies = array_map(
            static fn (string $value): string => $builder->buildEventEnvelope($dsn, $message, 'warning'),
            ['sessionId' => 'sess-1'],
        );

        return $bodies['sessionId'];
    }

    /**
     * @param array<string, mixed> $credentials
     */
    private function failWith(array $credentials, string $label): RuntimeException
    {
        try {
            throw new RuntimeException('boom ' . $label . ' DB_PASSWORD=hunter2 ' . count($credentials));
        } catch (RuntimeException $exception) {
            return $exception;
        }
    }
}
