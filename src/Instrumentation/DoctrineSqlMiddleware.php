<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Instrumentation;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer;
use SensitiveParameter;

use function microtime;

/**
 * Opt-in Doctrine DBAL middleware that records SQL spans and breadcrumbs.
 *
 * Only registered when `instrumentation.doctrine` is true and doctrine/dbal is installed.
 */
final class DoctrineSqlMiddleware implements Middleware
{
    public function __construct(
        private readonly SpanBuffer $spanBuffer,
        private readonly BreadcrumbBuffer $breadcrumbBuffer,
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function wrap(Driver $driver): Driver
    {
        return new BeaconTracingDriver($driver, $this->spanBuffer, $this->breadcrumbBuffer);
    }
}

/**
 * @internal
 */
final class BeaconTracingDriver extends AbstractDriverMiddleware
{
    public function __construct(
        Driver $driver,
        private readonly SpanBuffer $spanBuffer,
        private readonly BreadcrumbBuffer $breadcrumbBuffer,
    ) {
        parent::__construct($driver);
    }

    public function connect(
        #[SensitiveParameter]
        array $params,
    ): DriverConnection {
        return new BeaconTracingConnection(
            parent::connect($params),
            $this->spanBuffer,
            $this->breadcrumbBuffer,
        );
    }
}

/**
 * @internal
 */
final class BeaconTracingConnection extends AbstractConnectionMiddleware
{
    public function __construct(
        DriverConnection $connection,
        private readonly SpanBuffer $spanBuffer,
        private readonly BreadcrumbBuffer $breadcrumbBuffer,
    ) {
        parent::__construct($connection);
    }

    public function query(string $sql): Result
    {
        return $this->trace($sql, fn (): Result => parent::query($sql));
    }

    public function exec(string $sql): int|string
    {
        return $this->trace($sql, fn (): int|string => parent::exec($sql));
    }

    public function prepare(string $sql): Statement
    {
        $this->breadcrumbBuffer->add(
            SqlNormalizer::normalize($sql),
            'db.query',
            'info',
            ['op' => 'db.sql.prepare'],
            'query',
        );

        return parent::prepare($sql);
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function trace(string $sql, callable $callback): mixed
    {
        $start       = microtime(true);
        $description = SqlNormalizer::normalize($sql);

        try {
            return $callback();
        } finally {
            $end = microtime(true);
            $this->spanBuffer->add('db.sql.query', $description, $start, $end);
            $this->breadcrumbBuffer->add(
                $description,
                'db.query',
                'info',
                [
                    'op'       => 'db.sql.query',
                    'duration' => round(($end - $start) * 1000, 2),
                ],
                'query',
            );
        }
    }
}
