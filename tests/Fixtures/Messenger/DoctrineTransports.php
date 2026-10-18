<?php

declare(strict_types=1);

namespace Nowo\BeaconBundle\Tests\Fixtures\Messenger;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\DoctrineTransport;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Real Doctrine Messenger transports (`async` + `failed`) on one in-memory SQLite database (tests only).
 */
final class DoctrineTransports
{
    public readonly DoctrineTransport $async;

    public readonly DoctrineTransport $failed;

    public readonly DbalConnection $dbal;

    public function __construct()
    {
        $this->dbal   = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->async  = new DoctrineTransport(new Connection(['queue_name' => 'async'], $this->dbal), new PhpSerializer());
        $this->failed = new DoctrineTransport(new Connection(['queue_name' => 'failed'], $this->dbal), new PhpSerializer());
    }

    public function count(string $queue): int
    {
        return (int) $this->dbal->fetchOne('SELECT COUNT(*) FROM messenger_messages WHERE queue_name = ?', [$queue]);
    }
}
