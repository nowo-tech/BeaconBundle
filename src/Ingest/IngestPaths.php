<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Ingest;

use Nowo\Beacon\Dsn\Dsn;

use function rawurlencode;

/**
 * Beacon ingest URLs (D-20261110-04): the versioned canonical paths and the unversioned legacy paths, which Beacon
 * keeps as permanent aliases.
 *
 * | Endpoint | Versioned (canonical, Beacon ≥ 1.147) | Legacy (every Beacon) |
 * | --- | --- | --- |
 * | Envelope | `/api/ingest/v1/{projectId}/envelope/` | `/api/{projectId}/envelope/` |
 * | Remote commands | `/api/ingest/v1/{projectId}/messenger/commands/…` | `/api/{projectId}/messenger/commands/…` |
 *
 * The URLs never carry credentials.
 */
final class IngestPaths
{
    public const VERSIONED_PREFIX = '/api/ingest/v1/';

    public const LEGACY_PREFIX = '/api/';

    /**
     * Envelope ingest URL.
     */
    public static function envelopeUrl(Dsn $dsn, bool $versioned): string
    {
        return self::projectUrl($dsn, $versioned) . '/envelope/';
    }

    /**
     * Remote Messenger command URL; `$path` is `/messenger/commands/claim` or `/messenger/commands/{id}/result`.
     */
    public static function projectPathUrl(Dsn $dsn, string $path, bool $versioned): string
    {
        return self::projectUrl($dsn, $versioned) . $path;
    }

    /**
     * Whether an answer on the versioned path means "this Beacon has no such route" (older than 1.147): 404, or
     * 405 when another route matched the path.
     */
    public static function isRouteMissing(int $status): bool
    {
        return $status === 404 || $status === 405;
    }

    private static function projectUrl(Dsn $dsn, bool $versioned): string
    {
        return $dsn->getOrigin() . ($versioned ? self::VERSIONED_PREFIX : self::LEGACY_PREFIX) . rawurlencode($dsn->getProjectId());
    }
}
