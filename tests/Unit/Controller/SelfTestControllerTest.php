<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Controller;

use Nowo\BeaconBundle\Client\NullBeaconClient;
use Nowo\BeaconBundle\Controller\SelfTestController;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\SelfTest\BeaconSelfTest;
use Nowo\BeaconBundle\SelfTest\BeaconSelfTestException;
use Nowo\BeaconBundle\Tests\Fixtures\RecordingBeaconClient;
use PHPUnit\Framework\TestCase;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManager;
use Symfony\Component\Security\Csrf\TokenStorage\TokenStorageInterface;

use function in_array;
use function json_decode;

use const JSON_THROW_ON_ERROR;

final class SelfTestControllerTest extends TestCase
{
    private const DSN = 'https://pub:secret@beacon.example.com/7';

    private CsrfTokenManager $csrf;

    protected function setUp(): void
    {
        $storage = new class implements TokenStorageInterface {
            /** @var array<string, string> */
            private array $tokens = [];

            public function getToken(string $tokenId): string
            {
                return $this->tokens[$tokenId] ?? '';
            }

            public function setToken(string $tokenId, #[SensitiveParameter] string $token): void
            {
                $this->tokens[$tokenId] = $token;
            }

            public function removeToken(string $tokenId): ?string
            {
                $token = $this->tokens[$tokenId] ?? null;
                unset($this->tokens[$tokenId]);

                return $token;
            }

            public function hasToken(string $tokenId): bool
            {
                return isset($this->tokens[$tokenId]);
            }
        };
        $this->csrf = new CsrfTokenManager(null, $storage);
    }

    public function testStatusReturnsKindsAndToken(): void
    {
        $data = $this->json($this->controller()->status());

        self::assertSame('https://beacon.example.com', $data['status']['origin']);
        self::assertSame(BeaconSelfTest::UNCAUGHT_KINDS, $data['kinds']['uncaught']);
        self::assertSame(BeaconSelfTest::RETURNING_KINDS, $data['kinds']['returning']);
        self::assertSame('nowo_beacon_self_test', $data['csrf_token_id']);
        self::assertIsString($data['csrf_token']);
        self::assertTrue($this->csrf->isTokenValid(new CsrfToken('nowo_beacon_self_test', $data['csrf_token'])));
    }

    public function testTriggerReturningKindWithFormTokenAndHeader(): void
    {
        $client     = new RecordingBeaconClient();
        $controller = $this->controller($client);
        $token      = $this->csrf->getToken(SelfTestController::CSRF_TOKEN_ID)->getValue();

        $response = $controller->trigger(new Request([], ['_token' => $token]), 'captured-message');
        self::assertSame(200, $response->getStatusCode());
        $data = $this->json($response);
        self::assertSame('captured-message', $data['kind']);
        self::assertSame('message-id', $data['event_id']);
        self::assertSame('beacon_selftest', $data['tag']);

        $request = new Request();
        $request->headers->set('X-CSRF-Token', $token);
        self::assertSame(200, $controller->trigger($request, 'captured-exception')->getStatusCode());
        self::assertCount(1, $client->exceptions);
    }

    public function testTriggerUncaughtKindThrows(): void
    {
        $token = $this->csrf->getToken(SelfTestController::CSRF_TOKEN_ID)->getValue();

        $this->expectException(BeaconSelfTestException::class);
        $this->controller()->trigger(new Request([], ['_token' => $token]), 'uncaught-exception');
    }

    public function testTriggerRejectsBadTokenUnknownKindAndUnavailable(): void
    {
        $token = $this->csrf->getToken(SelfTestController::CSRF_TOKEN_ID)->getValue();

        self::assertSame(403, $this->controller()->trigger(new Request(), 'captured-message')->getStatusCode());
        self::assertSame(403, $this->controller()->trigger(new Request([], ['_token' => 'nope']), 'captured-message')->getStatusCode());
        self::assertSame(404, $this->controller()->trigger(new Request([], ['_token' => $token]), 'bogus')->getStatusCode());

        $unavailable = new SelfTestController(new BeaconSelfTest(new NullBeaconClient(), new BeaconDsnParser(), ''), [], $this->csrf);
        $response    = $unavailable->trigger(new Request([], ['_token' => $token]), 'captured-message');
        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('No Beacon DSN configured', $this->json($response)['error']);
    }

    public function testAccessControl(): void
    {
        $selfTest = new BeaconSelfTest(new RecordingBeaconClient(), new BeaconDsnParser(), self::DSN);

        // Roles configured but no security: fail closed.
        $noSecurity = new SelfTestController($selfTest, ['ROLE_ADMIN'], $this->csrf);
        self::assertSame(403, $noSecurity->status()->getStatusCode());
        self::assertSame(403, $noSecurity->trigger(new Request(), 'captured-message')->getStatusCode());

        $denied = new SelfTestController($selfTest, ['ROLE_ADMIN', 'ROLE_OPS'], $this->csrf, $this->checker([]));
        self::assertSame(403, $denied->status()->getStatusCode());

        $granted = new SelfTestController($selfTest, ['ROLE_ADMIN', 'ROLE_OPS'], $this->csrf, $this->checker(['ROLE_OPS']));
        self::assertSame(200, $granted->status()->getStatusCode());

        // Without CSRF support every call is refused.
        $noCsrf = new SelfTestController($selfTest, []);
        self::assertSame(500, $noCsrf->status()->getStatusCode());
        self::assertSame(500, $noCsrf->trigger(new Request(), 'captured-message')->getStatusCode());
    }

    private function controller(?RecordingBeaconClient $client = null): SelfTestController
    {
        return new SelfTestController(
            new BeaconSelfTest($client ?? new RecordingBeaconClient(), new BeaconDsnParser(), self::DSN),
            ['ROLE_ADMIN'],
            $this->csrf,
            $this->checker(['ROLE_ADMIN']),
        );
    }

    /**
     * @param list<string> $granted
     */
    private function checker(array $granted): AuthorizationCheckerInterface
    {
        $checker = $this->createStub(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturnCallback(static fn (mixed $attribute): bool => in_array($attribute, $granted, true));

        return $checker;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(JsonResponse $response): array
    {
        $data = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /* @var array<string, mixed> $data */
        return $data;
    }
}
