<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\Integration;

use Nette\Database\Connection;
use Websonette\ApplicationMcpServer\Persistence\TransactionManager;

final readonly class NetteTransactionManager implements TransactionManager
{
    public function __construct(private Connection $connection) {}
    public function run(\Closure $operation): mixed { return $this->connection->transaction($operation); }
}
