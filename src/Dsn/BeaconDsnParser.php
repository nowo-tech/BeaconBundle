<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Dsn;

use function in_array;
use function sprintf;

/**
 * Parses a Beacon DSN into {@see BeaconDsn}.
 */
final class BeaconDsnParser
{
    /**
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
            throw new InvalidBeaconDsnException('Invalid DSN. Expected format: https://PUBLIC_KEY@host:port/PROJECT_ID');
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidBeaconDsnException(sprintf('Unsupported DSN scheme "%s". Use http or https.', $scheme));
        }

        $publicKey = rawurldecode($parts['user']);
        if ($publicKey === '') {
            throw new InvalidBeaconDsnException('DSN public key (userinfo) must not be empty.');
        }

        $secretKey = isset($parts['pass']) ? rawurldecode($parts['pass']) : null;
        if ($secretKey !== null && $secretKey === '') {
            $secretKey = null;
        }

        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            throw new InvalidBeaconDsnException(sprintf('Invalid DSN port "%d".', $port));
        }

        $path = trim($parts['path'], '/');
        if ($path === '' || !ctype_digit($path)) {
            throw new InvalidBeaconDsnException('DSN path must be a numeric project id (e.g. https://KEY@host:9444/1).');
        }

        $projectId = (int) $path;
        if ($projectId < 1) {
            throw new InvalidBeaconDsnException('DSN project id must be a positive integer.');
        }

        return new BeaconDsn($scheme, $publicKey, $secretKey, $host, $port, $projectId);
    }
}
