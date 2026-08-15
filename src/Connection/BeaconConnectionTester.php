<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Connection;

use Nowo\BeaconBundle\Dsn\BeaconDsn;
use Nowo\BeaconBundle\Dsn\BeaconDsnParser;
use Nowo\BeaconBundle\Dsn\InvalidBeaconDsnException;
use Nowo\BeaconBundle\Envelope\EnvelopeBuilder;
use Nowo\BeaconBundle\Envelope\EnvelopeTransport;
use Nowo\BeaconBundle\Envelope\SendOptions;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function is_array;
use function is_string;
use function strlen;
use function substr;
use function trim;

/**
 * Parses the configured Beacon DSN and optionally POSTs a sync test Envelope.
 *
 * Always uses synchronous HTTP so the result reflects the real ingest ACK,
 * regardless of `nowo_beacon.transport.mode`.
 */
final class BeaconConnectionTester
{
    public function __construct(
        private readonly BeaconDsnParser $parser,
        private readonly HttpClientInterface $httpClient,
        private readonly bool $reportingEnabled,
        private readonly string $dsn,
        private readonly bool $verifyPeer = true,
        private readonly float $timeout = 5.0,
        private readonly string $environment = 'prod',
        private readonly ?string $release = null,
        private readonly string $serverName = 'unknown',
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Validate (and optionally probe) the configured Beacon connection.
     *
     * @param bool $checkOnly When true, only parse/display the DSN (no HTTP POST)
     * @param string $message Event message used for the test Envelope
     */
    public function test(bool $checkOnly = false, string $message = 'BeaconBundle connection test'): ConnectionTestResult
    {
        $dsnString = trim($this->dsn);
        if ($dsnString === '' || str_starts_with($dsnString, '%')) {
            return new ConnectionTestResult(
                false,
                'Beacon DSN is empty. Set BEACON_DSN (or nowo_beacon.dsn) to a valid Symfony Beacon DSN.',
            );
        }

        try {
            $dsn = $this->parser->parse($dsnString);
        } catch (InvalidBeaconDsnException $exception) {
            return new ConnectionTestResult(
                false,
                'Invalid Beacon DSN: ' . $exception->getMessage(),
            );
        }

        $target = $this->sanitizeTarget($dsn);

        if ($checkOnly) {
            $note = $this->reportingEnabled
                ? 'DSN is valid. Automatic reporting is enabled.'
                : 'DSN is valid. Note: nowo_beacon.enabled is false (automatic reporting is off).';

            return new ConnectionTestResult(true, $note, $target);
        }

        $builder = new EnvelopeBuilder($this->environment, $this->release, $this->serverName, new SendOptions());
        $body    = $builder->buildEventEnvelope(
            $dsn,
            $message,
            'info',
            null,
            [
                'source' => 'nowo:beacon:test',
            ],
        );
        $eventId   = $this->extractEventId($body);
        $transport = new EnvelopeTransport(
            $this->httpClient,
            $dsn,
            $this->verifyPeer,
            $this->timeout,
            $this->logger ?? new NullLogger(),
        );
        $result = $transport->sendDetailed($body);

        if ($result->isAccepted()) {
            $suffix = $this->reportingEnabled
                ? ''
                : ' Note: nowo_beacon.enabled is false (automatic reporting remains off).';

            return new ConnectionTestResult(
                true,
                'Beacon ingest accepted the test envelope (HTTP '
                    . (string) ($result->getStatusCode() ?? 200)
                    . ').' . $suffix,
                $target,
                $eventId,
                $result->getStatusCode(),
                true,
            );
        }

        $status = $result->getStatusCode();
        $hint   = $this->failureHint($status, $result->getErrorMessage());

        return new ConnectionTestResult(
            false,
            $hint,
            $target,
            $eventId,
            $status,
            true,
        );
    }

    /**
     * @return array{
     *     origin: string,
     *     project_id: string,
     *     public_key: string,
     *     envelope_url: string,
     *     reporting_enabled: bool
     * }
     */
    private function sanitizeTarget(BeaconDsn $dsn): array
    {
        $publicKey = $dsn->getPublicKey();
        if (strlen($publicKey) > 8) {
            $publicKey = substr($publicKey, 0, 8) . '…';
        }

        return [
            'origin'            => $dsn->getOrigin(),
            'project_id'        => $dsn->getProjectId(),
            'public_key'        => $publicKey,
            'envelope_url'      => $dsn->getEnvelopeUrl(),
            'reporting_enabled' => $this->reportingEnabled,
        ];
    }

    private function failureHint(?int $status, ?string $transportError): string
    {
        if ($transportError !== null && $transportError !== '') {
            return 'Beacon ingest transport failed: ' . $transportError;
        }

        return match ($status) {
            401, 403 => 'Beacon ingest rejected authentication (HTTP '
                . (string) $status
                . '). Confirm BEACON_DSN includes public:secret and matches the project.',
            404     => 'Beacon ingest returned HTTP 404. Confirm the project id in the DSN path exists on this server.',
            429     => 'Beacon ingest rate limited (HTTP 429). Retry later.',
            default => 'Beacon ingest rejected the test envelope'
                . ($status !== null ? ' (HTTP ' . $status . ')' : '')
                . '.',
        };
    }

    private function extractEventId(string $envelopeBody): ?string
    {
        $firstLine = strtok($envelopeBody, "\n") ?: '';
        $decoded   = json_decode($firstLine, true);
        $eventId   = is_array($decoded) ? ($decoded['event_id'] ?? null) : null;

        return is_string($eventId) ? $eventId : null;
    }
}
