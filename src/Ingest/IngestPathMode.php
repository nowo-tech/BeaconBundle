<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Ingest;

/**
 * Which Beacon ingest paths the bundle calls (`nowo_beacon.ingest_paths`).
 *
 * - `auto` (default): the versioned path first; on HTTP 404 / 405 the same request once more on the legacy path, and
 *   the answer is remembered per Beacon target (origin + project id) ({@see IngestPathResolver}).
 * - `versioned`: always `/api/ingest/v1/{projectId}/…` (Beacon ≥ 1.147).
 * - `legacy`: always the unversioned `/api/{projectId}/…` paths (permanent aliases on every Beacon).
 */
enum IngestPathMode: string
{
    case Auto      = 'auto';
    case Versioned = 'versioned';
    case Legacy    = 'legacy';
}
