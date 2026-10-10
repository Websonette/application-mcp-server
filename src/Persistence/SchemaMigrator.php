<?php
declare(strict_types=1);

namespace Websonette\ApplicationMcpServer\Persistence;

final readonly class SchemaMigrator
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function installed(): bool
    {
        try {
            $statement = $this->pdo->prepare('SELECT version FROM mcp_schema_migrations WHERE version = 1');
            $statement->execute();
            return $statement->fetchColumn() !== false;
        } catch (\PDOException) {
            return false;
        }
    }

    public function migrate(): void
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new \LogicException('Supported persistence drivers are SQLite and MySQL/MariaDB.');
        }
        $sql = file_get_contents(dirname(__DIR__, 2) . '/resources/migrations/001.' . $driver . '.sql');
        if ($sql === false) {
            throw new \RuntimeException('Migration resource missing.');
        }
        // Statements are repeatable. MySQL DDL is not transactionally rolled back.
        foreach (explode(';', $sql) as $statement) {
            if (trim($statement) !== '') {
                $this->pdo->exec($statement);
            }
        }
        $this->pdo->exec($driver === 'sqlite'
            ? 'INSERT OR IGNORE INTO mcp_schema_migrations(version) VALUES (1)'
            : 'INSERT IGNORE INTO mcp_schema_migrations(version) VALUES (1)');
    }
}
