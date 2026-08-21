<?php

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * Applies SQLite PRAGMAs on every new DBAL connection.
 *
 * SQLite PRAGMAs like `busy_timeout` are per-connection (they do not persist
 * across separate PDO connections), so every PHP process/request opening its
 * own connection needs to set them again. Without `busy_timeout`, concurrent
 * writers (e.g. multiple feature-branch agents each running their own dev
 * server + tests against the same SQLite file at once) get an immediate
 * "database is locked" error instead of a short automatic retry. WAL mode is
 * stored in the database file header and persists once set, but we
 * re-assert it here too for safety. `query()` is used rather than `exec()`
 * because SQLite PRAGMA statements return a result row (e.g. the new
 * journal mode) that some drivers expect to be consumed.
 */
#[AsMiddleware]
class SqlitePragmaMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class ($driver) extends AbstractDriverMiddleware {
            public function connect(array $params): DriverConnection
            {
                $connection = parent::connect($params);
                $connection->query('PRAGMA busy_timeout = 5000');
                $connection->query('PRAGMA journal_mode = WAL');

                return $connection;
            }
        };
    }
}
