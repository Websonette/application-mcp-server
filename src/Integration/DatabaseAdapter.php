<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\Integration;

use Nette\Database\Connection;
use Nette\Database\Explorer;
use Websonette\ApplicationMcpServer\Persistence\TransactionManager;

final readonly class DatabaseAdapter
{
    public \PDO $pdo;
    public ?TransactionManager $transactions;

    public function __construct(\PDO|Connection|Explorer $database)
    {
        $connection = $database instanceof Explorer ? $database->getConnection() : $database;
        $this->pdo = $connection instanceof \PDO ? $connection : $connection->getPdo();
        $this->transactions = $connection instanceof Connection ? new NetteTransactionManager($connection) : null;
    }
    public function getPdo(): \PDO { return $this->pdo; }
    public function getTransactions(): ?TransactionManager { return $this->transactions; }
}
