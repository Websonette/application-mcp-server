<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\Tests;

use PHPUnit\Framework\TestCase;
use Websonette\ApplicationMcpServer\Persistence\SchemaMigrator;

final class MigrationTest extends TestCase
{
    public function testLegacySchemaIsRejectedWithoutChangingAnyData(): void
    {
        $pdo = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE mcp_access_tokens (jti TEXT)');
        $pdo->exec("INSERT INTO mcp_access_tokens VALUES ('legacy-token')");
        try { (new SchemaMigrator($pdo))->migrate(); self::fail('Legacy schema accepted.'); }
        catch (\LogicException) {
            self::assertSame('legacy-token', $this->scalar($pdo, 'SELECT jti FROM mcp_access_tokens'));
            self::assertSame(0, (int) $this->scalar($pdo, "SELECT COUNT(*) FROM sqlite_master WHERE name = 'mcp_schema_migrations'"));
        }
    }
    public function testVersionOneUpgradeKeepsClientsAndAuditAndIsRepeatable(): void
    {
        $pdo = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $sql = file_get_contents(dirname(__DIR__) . '/resources/migrations/001.sqlite.sql');
        self::assertIsString($sql);
        $pdo->exec($sql);
        $pdo->exec('INSERT INTO mcp_schema_migrations VALUES (1)');
        $pdo->exec("INSERT INTO mcp_clients (id, name, redirect_uris, scopes, created_at) VALUES ('legacy-package-client', 'Client', '[]', '[]', 1)");
        $migrator = new SchemaMigrator($pdo);
        self::assertFalse($migrator->installed());
        $migrator->migrate();
        $migrator->migrate();
        self::assertTrue($migrator->installed());
        self::assertSame(1, (int) $this->scalar($pdo, "SELECT COUNT(*) FROM mcp_clients WHERE id = 'legacy-package-client'"));
        self::assertSame(2, (int) $this->scalar($pdo, 'SELECT COUNT(*) FROM mcp_schema_migrations'));
    }

    private function scalar(\PDO $pdo, string $sql): mixed
    {
        $statement = $pdo->query($sql);
        self::assertNotFalse($statement);
        return $statement->fetchColumn();
    }

    public function testCliStatusIsReadOnlyAndReportsCurrentSchemaVersion(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'mcp-cli-');
        self::assertIsString($file);
        $previous = getenv('MCP_CLI_TEST_DSN');
        putenv('MCP_CLI_TEST_DSN=sqlite:' . $file);
        try {
            $pdo = new \PDO('sqlite:' . $file);
            (new SchemaMigrator($pdo))->migrate();
            self::assertSame('Schema version 2 installed.' . PHP_EOL, $this->cliStatus());
            $pdo->exec('DELETE FROM mcp_schema_migrations WHERE version = 2');
            self::assertSame('Schema is not current. Run migrate explicitly.' . PHP_EOL, $this->cliStatus());
            self::assertSame(1, (int) $this->scalar($pdo, 'SELECT MAX(version) FROM mcp_schema_migrations'));
        } finally {
            putenv($previous === false ? 'MCP_CLI_TEST_DSN' : 'MCP_CLI_TEST_DSN=' . $previous);
            unset($pdo);
            unlink($file);
        }
    }

    private function cliStatus(): string
    {
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/bin/websonette-mcp', 'status',
            '--bootstrap=' . __DIR__ . '/Support/cli-bootstrap.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), is_string($error) ? $error : 'CLI failed.');
        self::assertIsString($output);
        return $output;
    }
}
