<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Dsn;

use function in_array;
use function is_string;
use function sprintf;

/**
 * Parses a Beacon DSN into {@see BeaconDsn}.
 */
final class BeaconDsnParser
{
    /**
     * Parse and validate a Beacon DSN string.
     *
     * @throws InvalidBeaconDsnException
     */
    public function parse(string $dsn): BeaconDsn
    {
        $dsn = trim($dsn);
        if ($dsn === '') {
            throw new InvalidBeaconDsnException('DSN must not be empty.');
        }

        $parts = parse_url($dsn);
        if ($parts === false || !isset($parts['scheme'], $parts['host'], $parts['user'], $parts['path'])) {
            throw new InvalidBeaconDsnException('Invalid DSN. Expected format: https://PUBLIC_KEY:SECRET_KEY@host:port/PROJECT_ID');
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidBeaconDsnException(sprintf('Unsupported DSN scheme "%s". Use http or https.', $scheme));
        }

        $publicKey = rawurldecode($parts['user']);
        if ($publicKey === '') {
            throw new InvalidBeaconDsnException('DSN public key (userinfo username) must not be empty.');
        }

        if (!isset($parts['pass']) || !is_string($parts['pass']) || $parts['pass'] === '') {
            throw new InvalidBeaconDsnException('DSN secret key is required. Use https://PUBLIC_KEY:SECRET_KEY@host/PROJECT_ID (Symfony Beacon rejects public-key-only auth when the API key has a secret).');
        }

        $secretKey = rawurldecode($parts['pass']);
        // Defensive: non-empty userinfo password that url-decodes to empty (not reachable via parse_url alone).
        if ($secretKey === '') { // @codeCoverageIgnore
            throw new InvalidBeaconDsnException('DSN secret key must not be empty.'); // @codeCoverageIgnore
        }

        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? $parts['port'] : null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new InvalidBeaconDsnException(sprintf('Invalid DSN port "%d".', $port));
        }

        $path = trim($parts['path'], '/');
        if ($path === '' || !ctype_digit($path)) {
            throw new InvalidBeaconDsnException('DSN path must be a numeric project id (e.g. https://KEY:SECRET@host:9444/1).');
        }

        $projectId = (int) $path;
        if ($projectId < 1) {
            throw new InvalidBeaconDsnException('DSN project id must be a positive integer.');
        }

        return new BeaconDsn($scheme, $publicKey, $secretKey, $host, $port, $projectId);
    }
}
