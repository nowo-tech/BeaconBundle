<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Envelope;

use InvalidArgumentException;
use Nowo\BeaconBundle\Context\UserContextProviderInterface;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Envelope\EnvelopeBuilder;
use Nowo\BeaconBundle\Envelope\SendOptions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\HttpKernel\Kernel;

use const JSON_THROW_ON_ERROR;
use const PHP_VERSION;

final class EnvelopeBuilderTest extends TestCase
{
    public function testBuildsNdjsonEnvelopeWithExceptionExtraAndFingerprint(): void
    {
        $dsn       = (new BeaconDsnParser())->parse('https://pubkey@localhost:9444/1');
        $builder   = new EnvelopeBuilder('test', '1.0.0', 'ci-host');
        $throwable = new RuntimeException('outer boom', 0, new InvalidArgumentException('inner boom'));

        $body = $builder->buildEventEnvelope(
            $dsn,
            'boom',
            'error',
            $throwable,
            ['request_id' => 'abc-123'],
            ['runtime', 'boom'],
        );

        [$header, $item, $payload] = $this->decodeEnvelope($body);

        self::assertSame('event', $item['type']);
        self::assertSame('application/json', $item['content_type']);
        self::assertSame($header['event_id'], $payload['event_id']);
        self::assertSame('https://pubkey@localhost:9444/1', $header['dsn']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $header['event_id']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $header['sent_at']);
        self::assertSame($header['sent_at'], $payload['datetime']);
        self::assertIsFloat($payload['timestamp']);
        self::assertSame('php', $payload['platform']);
        self::assertSame('nowo.beacon', $payload['logger']);
        self::assertSame('ci-host', $payload['server_name']);
        self::assertSame('test', $payload['environment']);
        self::assertSame('1.0.0', $payload['release']);
        self::assertSame('error', $payload['level']);
        self::assertSame('boom', $payload['message']);
        self::assertSame(['request_id' => 'abc-123'], $payload['extra']);
        self::assertSame(['runtime', 'boom'], $payload['fingerprint']);
        self::assertSame(PHP_VERSION, $payload['contexts']['runtime']['version']);
        self::assertSame(Kernel::VERSION, $payload['contexts']['framework']['version']);
        self::assertArrayHasKey('exception', $payload);
        self::assertCount(2, $payload['exception']['values']);
        self::assertSame(InvalidArgumentException::class, $payload['exception']['values'][0]['type']);
        self::assertSame('inner boom', $payload['exception']['values'][0]['value']);
        self::assertSame(RuntimeException::class, $payload['exception']['values'][1]['type']);
        self::assertSame('outer boom', $payload['exception']['values'][1]['value']);
        self::assertIsString($payload['culprit']);
        self::assertArrayNotHasKey('user', $payload);
    }

    public function testRespectsSendOptionsOmissionsAndUserOptIn(): void
    {
        $dsn = (new BeaconDsnParser())->parse('https://pubkey@localhost:9444/1');
        $userProvider = new class implements UserContextProviderInterface {
            public function getUserContext(): ?array
            {
                return ['id' => '42', 'username' => 'alice', 'email' => 'alice@example.com'];
            }
        };
        $builder = new EnvelopeBuilder(
            'prod',
            '9.9.9',
            'secret-host',
            new SendOptions(
                environment: false,
                release: false,
                serverName: false,
                stacktrace: false,
                request: false,
                user: true,
                runtime: false,
                framework: false,
                os: false,
            ),
            $userProvider,
        );

        $body = $builder->buildEventEnvelope($dsn, 'msg', 'error', new RuntimeException('no frames'));
        [, , $payload] = $this->decodeEnvelope($body);

        self::assertArrayNotHasKey('environment', $payload);
        self::assertArrayNotHasKey('release', $payload);
        self::assertArrayNotHasKey('server_name', $payload);
        self::assertArrayNotHasKey('contexts', $payload);
        self::assertArrayNotHasKey('culprit', $payload);
        self::assertArrayNotHasKey('stacktrace', $payload['exception']['values'][0]);
        self::assertSame(['id' => '42', 'username' => 'alice', 'email' => 'alice@example.com'], $payload['user']);
    }

    public function testUsesThrowableMessageWhenMessageIsEmpty(): void
    {
        $dsn     = (new BeaconDsnParser())->parse('https://pubkey@localhost:9444/1');
        $builder = new EnvelopeBuilder('test', '1.0.0', 'ci-host');
        $body    = $builder->buildEventEnvelope($dsn, '', 'warning', new RuntimeException('fallback message'));

        [, , $payload] = $this->decodeEnvelope($body);

        self::assertSame('warning', $payload['level']);
        self::assertSame('fallback message', $payload['message']);
    }

    public function testOmitsEmptyReleaseExtraAndFingerprint(): void
    {
        $dsn     = (new BeaconDsnParser())->parse('https://pubkey@localhost:9444/1');
        $builder = new EnvelopeBuilder('prod', '', 'app-host');
        $body    = $builder->buildEventEnvelope($dsn, '', 'info', null, [], []);

        [, , $payload] = $this->decodeEnvelope($body);

        self::assertSame('', $payload['message']);
        self::assertSame('info', $payload['level']);
        self::assertArrayNotHasKey('release', $payload);
        self::assertArrayNotHasKey('extra', $payload);
        self::assertArrayNotHasKey('fingerprint', $payload);
        self::assertArrayNotHasKey('exception', $payload);
        self::assertArrayNotHasKey('culprit', $payload);
    }

    public function testPreservesProvidedLevelValues(): void
    {
        $dsn     = (new BeaconDsnParser())->parse('https://pubkey@localhost:9444/1');
        $builder = new EnvelopeBuilder('test', null, 'ci-host');

        foreach (['debug', 'info', 'warning', 'error', 'fatal'] as $level) {
            $body          = $builder->buildEventEnvelope($dsn, 'message', $level);
            [, , $payload] = $this->decodeEnvelope($body);
            self::assertSame($level, $payload['level']);
        }
    }

    public function testGuessCulpritUsesFileWhenTraceIsEmpty(): void
    {
        $builder = new EnvelopeBuilder('test', null, 'ci-host');
        $method  = new ReflectionMethod(EnvelopeBuilder::class, 'formatCulprit');
        $method->setAccessible(true);

        self::assertSame('/tmp/empty-trace.php:42', $method->invoke($builder, [], '/tmp/empty-trace.php', 42));
    }

    public function testGuessCulpritFallsBackToFunctionName(): void
    {
        $builder = new EnvelopeBuilder('test', null, 'ci-host');
        $method  = new ReflectionMethod(EnvelopeBuilder::class, 'formatCulprit');
        $method->setAccessible(true);

        $culprit = $method->invoke($builder, [
            [
                'function' => 'procedural_handler',
                'file'     => '/tmp/demo.php',
                'line'     => 12,
            ],
        ], '/tmp/demo.php', 12);

        self::assertSame('procedural_handler', $culprit);
    }

    public function testEncodeFailureThrowsRuntimeException(): void
    {
        $builder = new EnvelopeBuilder('test', null, 'ci-host');
        $method  = new ReflectionMethod(EnvelopeBuilder::class, 'encode');
        $method->setAccessible(true);

        $data         = [];
        $data['loop'] = &$data;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to encode envelope JSON:');

        $method->invoke($builder, $data);
    }

    public function testAttachesBreadcrumbsAndClearsBuffer(): void
    {
        $dsn = (new BeaconDsnParser())->parse('https://pubkey@localhost:9444/1');
        $buffer = new \Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer();
        $buffer->add('step-1', 'demo');
        $builder = new EnvelopeBuilder('test', null, 'ci', new SendOptions(), null, $buffer);

        [, $item, $payload] = $this->decodeEnvelope($builder->buildEventEnvelope($dsn, 'with crumbs'));
        self::assertSame('event', $item['type']);
        self::assertCount(1, $payload['breadcrumbs']['values']);
        self::assertSame('step-1', $payload['breadcrumbs']['values'][0]['message']);
        self::assertSame([], $buffer->all());
    }

    public function testBuildTransactionEnvelope(): void
    {
        $dsn = (new BeaconDsnParser())->parse('https://pubkey@localhost:9444/1');
        $builder = new EnvelopeBuilder('test', null, 'ci');
        $start = 1000.0;
        $end = 1000.25;
        [, $item, $payload] = $this->decodeEnvelope($builder->buildTransactionEnvelope(
            $dsn,
            'demo.checkout',
            $start,
            $end,
            [
                [
                    'op' => 'db.query',
                    'description' => 'SELECT 1',
                    'span_id' => 'abc123',
                    'start_timestamp' => $start,
                    'timestamp' => $start + 0.1,
                ],
            ],
            ['demo' => true],
        ));

        self::assertSame('transaction', $item['type']);
        self::assertSame('demo.checkout', $payload['transaction']);
        self::assertEqualsWithDelta($start, $payload['start_timestamp'], 0.0001);
        self::assertCount(1, $payload['spans']);
        self::assertTrue($payload['extra']['demo']);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function decodeEnvelope(string $body): array
    {
        $lines = array_values(array_filter(explode("\n", $body), static fn (string $line): bool => $line !== ''));
        self::assertCount(3, $lines);

        return [
            json_decode($lines[0], true, 512, JSON_THROW_ON_ERROR),
            json_decode($lines[1], true, 512, JSON_THROW_ON_ERROR),
            json_decode($lines[2], true, 512, JSON_THROW_ON_ERROR),
        ];
    }
}
