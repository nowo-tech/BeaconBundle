<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Context;

use Nowo\Beacon\Context\DatabaseExceptionContext as CoreDatabaseExceptionContext;
use PDOException;
use Throwable;

/**
 * Best-effort SQL/SQLSTATE facts from a throwable chain for Envelope {@code contexts.db}.
 *
 * Does not require doctrine/dbal; uses {@see PDOException} and duck-typed DBAL APIs. Forwards to beacon-php's
 * {@see CoreDatabaseExceptionContext} (identical rules; the builder uses it for `contexts.db`).
 */
final class DatabaseExceptionContext
{
    public const MAX_SQL_LENGTH = CoreDatabaseExceptionContext::MAX_SQL_LENGTH;

    /**
     * @return array<string, mixed>|null
     */
    public static function fromThrowable(Throwable $throwable): ?array
    {
        return CoreDatabaseExceptionContext::fromThrowable($throwable);
    }

    public static function scrubSql(string $sql): string
    {
        return CoreDatabaseExceptionContext::scrubSql($sql);
    }
}
