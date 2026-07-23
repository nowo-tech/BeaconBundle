<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Unit\Instrumentation;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Nowo\BeaconBundle\Breadcrumb\BreadcrumbBuffer;
use Nowo\BeaconBundle\Instrumentation\DoctrineSqlMiddleware;
use Nowo\BeaconBundle\Instrumentation\SpanBuffer;
use PHPUnit\Framework\TestCase;

final class DoctrineSqlMiddlewareTest extends TestCase
{
    public function testRecordsSpansAndBreadcrumbsForSql(): void
    {
        $spans  = new SpanBuffer();
        $crumbs = new BreadcrumbBuffer();

        $config = new Configuration();
        $config->setMiddlewares([new DoctrineSqlMiddleware($spans, $crumbs)]);

        $connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ], $config);

        $connection->executeStatement('CREATE TABLE demo (id INTEGER)');
        $connection->executeQuery('SELECT * FROM demo WHERE id = 1');
        $connection->prepare('SELECT id FROM demo WHERE id = ?');

        self::assertNotEmpty($spans->all());
        self::assertNotEmpty($crumbs->all());
        self::assertSame('db.sql.query', $spans->all()[0]['op']);
    }
}
