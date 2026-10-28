<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Controller;

use Nowo\BeaconBundle\SelfTest\BeaconSelfTest;
use Nowo\BeaconBundle\SelfTest\SelfTestUnavailableException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

use function in_array;
use function is_string;

/**
 * Optional JSON endpoint for the Beacon self-test (`nowo_beacon.self_test.http.enabled`, off by default).
 *
 * - GET  {path}         → status, kinds and a CSRF token
 * - POST {path}/{kind}  → run the kind (`_token` form field or `X-CSRF-Token` header).
 *   Returning kinds answer JSON; uncaught kinds throw so kernel.exception reports them (HTTP 500 by design).
 *
 * Access: the user must be granted at least one of the configured roles. An empty list denies (fail closed); to rely on
 * the firewall alone list `IS_AUTHENTICATED` (or `PUBLIC_ACCESS`) explicitly.
 * Without symfony/security-csrf (framework.csrf_protection) every call is refused.
 */
final readonly class SelfTestController
{
    public const CSRF_TOKEN_ID = 'nowo_beacon_self_test';

    /**
     * @param list<string> $roles
     */
    public function __construct(
        private BeaconSelfTest $selfTest,
        private array $roles = ['ROLE_ADMIN'],
        private ?CsrfTokenManagerInterface $csrfTokenManager = null,
        private ?AuthorizationCheckerInterface $authorizationChecker = null,
    ) {
    }

    public function status(): JsonResponse
    {
        $refused = $this->refuse();
        if ($refused instanceof JsonResponse) {
            return $refused;
        }

        /** @var CsrfTokenManagerInterface $csrf checked by refuse() */
        $csrf = $this->csrfTokenManager;

        return new JsonResponse([
            'status' => $this->selfTest->status(),
            'kinds'  => [
                'uncaught'  => BeaconSelfTest::UNCAUGHT_KINDS,
                'returning' => BeaconSelfTest::RETURNING_KINDS,
            ],
            'tag'           => BeaconSelfTest::TAG,
            'csrf_token_id' => self::CSRF_TOKEN_ID,
            'csrf_token'    => $csrf->getToken(self::CSRF_TOKEN_ID)->getValue(),
        ]);
    }

    public function trigger(Request $request, string $kind): JsonResponse
    {
        $refused = $this->refuse();
        if ($refused instanceof JsonResponse) {
            return $refused;
        }

        /** @var CsrfTokenManagerInterface $csrf checked by refuse() */
        $csrf  = $this->csrfTokenManager;
        $token = $request->request->get('_token') ?? $request->headers->get('X-CSRF-Token');
        if (!is_string($token) || !$csrf->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $token))) {
            return $this->error('Invalid CSRF token.', Response::HTTP_FORBIDDEN);
        }

        if (!in_array($kind, BeaconSelfTest::kinds(), true)) {
            return $this->error('Unknown self-test kind.', Response::HTTP_NOT_FOUND);
        }

        try {
            // Uncaught kinds throw here on purpose.
            $result = $this->selfTest->run($kind);
        } catch (SelfTestUnavailableException $e) {
            return $this->error($e->getMessage(), Response::HTTP_CONFLICT);
        }

        return new JsonResponse($result->toArray());
    }

    private function refuse(): ?JsonResponse
    {
        if (!$this->isGranted()) {
            return $this->error('Access denied.', Response::HTTP_FORBIDDEN);
        }

        if (!$this->csrfTokenManager instanceof CsrfTokenManagerInterface) {
            return $this->error('CSRF protection is unavailable: install symfony/security-csrf and enable framework.csrf_protection.', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return null;
    }

    private function isGranted(): bool
    {
        if (!$this->authorizationChecker instanceof AuthorizationCheckerInterface) {
            return false; // Fail closed when roles are configured but security is not installed.
        }

        foreach ($this->roles as $role) {
            if ($this->authorizationChecker->isGranted($role)) {
                return true;
            }
        }

        return false;
    }

    private function error(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
