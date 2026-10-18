<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Messenger\RemoteRetry;

use DateTimeImmutable;
use JsonException;
use Nowo\BeaconBundle\Client\ClientUserAgent;
use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function in_array;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function mb_substr;
use function min;
use function rawurlencode;
use function str_starts_with;
use function strlen;
use function strtotime;
use function substr;
use function trim;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Outbound HTTP calls of remote Messenger commands (claim + result).
 *
 * Direct HttpClient requests (never the Envelope transport, never a Messenger message), so they behave
 * the same under every `transport.mode`. Both calls send `Authorization: Bearer bwk_…` and JSON, with a
 * 3-second timeout independent of `nowo_beacon.timeout`.
 */
final class RemoteCommandClient
{
    public const TIMEOUT = 3.0;

    /** Highest `Retry-After` / `poll_after` honoured, in seconds. */
    public const MAX_DELAY = 3600;

    /** Response bodies larger than this are not parsed. */
    private const MAX_BODY_BYTES = 65536;

    private readonly string $userAgent;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly RemoteRetryCredentials $credentials,
        private readonly string $serverName,
        private readonly string $environment,
        private readonly bool $verifyPeer = true,
        private readonly ?ClockInterface $clock = null,
        ?string $userAgent = null,
    ) {
        $this->userAgent = $userAgent ?? ClientUserAgent::resolve();
    }

    /**
     * Server name reported with claims and results.
     */
    public function serverName(): string
    {
        return $this->serverName;
    }

    /**
     * Claim at most `$max` commands for the listed failure transports.
     *
     * @param list<array{name: string, listable: bool}> $failureTransports
     *
     * @throws RemoteCommandException
     */
    public function claim(array $failureTransports, int $max = 1): ClaimResponse
    {
        $data = $this->post('/messenger/commands/claim', [
            'server_name'        => mb_substr($this->serverName, 0, 255),
            'environment'        => $this->environment,
            'bundle_version'     => $this->bundleVersion(),
            'failure_transports' => $failureTransports,
            'max'                => $max,
        ], true);

        $commands = [];
        $entries  = $data['commands'] ?? [];
        foreach (is_array($entries) ? $entries : [] as $entry) {
            $command = is_array($entry) ? RemoteCommand::fromArray($entry) : null;
            if ($command instanceof RemoteCommand) {
                $commands[] = $command;
            }
        }

        $pollAfter = $data['poll_after'] ?? null;

        return new ClaimResponse(
            $commands,
            is_int($pollAfter) ? max(0, min(self::MAX_DELAY, $pollAfter)) : null,
        );
    }

    /**
     * Report the outcome of a claimed command.
     *
     * @throws RemoteCommandException
     */
    public function postResult(RemoteCommand $command, CommandResult $result): void
    {
        $this->post(
            '/messenger/commands/' . rawurlencode($command->id) . '/result',
            $result->toPayload($command->claimToken, $this->serverName),
            false,
        );
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws RemoteCommandException
     *
     * @return array<mixed>
     */
    private function post(string $path, array $payload, bool $isClaim): array
    {
        $dsn = $this->credentials->dsn();
        $key = $this->credentials->workerKey();
        if (!$dsn instanceof BeaconDsn || !$key instanceof WorkerKey) {
            throw new RemoteCommandException(RemoteCommandException::KIND_AUTH, (string) $this->credentials->problem());
        }

        $options = [
            'headers' => [
                'Authorization' => $key->authorizationHeader(),
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'User-Agent'    => $this->userAgent,
            ],
            'body'         => json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'timeout'      => self::TIMEOUT,
            'max_duration' => self::TIMEOUT,
        ];
        if (!$this->verifyPeer) {
            $options['verify_peer'] = false;
            $options['verify_host'] = false;
        }

        $url = $dsn->getOrigin() . '/api/' . rawurlencode($dsn->getProjectId()) . $path;

        try {
            $response = $this->httpClient->request('POST', $url, $options);
            $status   = $response->getStatusCode();
            $headers  = $response->getHeaders(false);
            $body     = $status === 200 ? $response->getContent(false) : '';
        } catch (ExceptionInterface $e) {
            throw new RemoteCommandException(RemoteCommandException::KIND_UNAVAILABLE, 'Beacon unreachable (' . $e::class . ').');
        }

        if ($status === 200) {
            return $this->decode($body);
        }

        $kind = $this->classify($status, $isClaim);

        throw new RemoteCommandException($kind, 'Beacon answered HTTP ' . $status . '.', $status, $kind === RemoteCommandException::KIND_RATE_LIMITED ? $this->retryAfter($headers['retry-after'][0] ?? null) : null);
    }

    private function classify(int $status, bool $isClaim): string
    {
        if ($status === 401 || $status === 403 || ($isClaim && $status === 404)) {
            return RemoteCommandException::KIND_AUTH;
        }

        if ($status === 429 || $status === 503) {
            return RemoteCommandException::KIND_RATE_LIMITED;
        }

        if (!$isClaim && $status >= 400 && $status < 500 && !in_array($status, [408, 425], true)) {
            return RemoteCommandException::KIND_REJECTED;
        }

        return RemoteCommandException::KIND_UNAVAILABLE;
    }

    /**
     * @throws RemoteCommandException
     *
     * @return array<mixed>
     */
    private function decode(string $body): array
    {
        if (isset($body[self::MAX_BODY_BYTES])) {
            throw new RemoteCommandException(RemoteCommandException::KIND_UNAVAILABLE, 'Beacon response too large.', 200);
        }

        if (trim($body) === '') {
            return [];
        }

        try {
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RemoteCommandException(RemoteCommandException::KIND_UNAVAILABLE, 'Beacon response is not JSON.', 200);
        }

        return is_array($data) ? $data : [];
    }

    /**
     * `Retry-After` in seconds (delta-seconds or HTTP-date), clamped to 1 … {@see MAX_DELAY}.
     */
    private function retryAfter(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        if (is_numeric($value)) {
            return max(1, min(self::MAX_DELAY, (int) $value));
        }

        $at = strtotime($value);
        if ($at === false) {
            return null;
        }

        $now = ($this->clock?->now() ?? new DateTimeImmutable())->getTimestamp();

        return max(1, min(self::MAX_DELAY, $at - $now));
    }

    private function bundleVersion(): string
    {
        $prefix = 'beacon-bundle/';

        return str_starts_with($this->userAgent, $prefix) ? substr($this->userAgent, strlen($prefix)) : $this->userAgent;
    }
}
