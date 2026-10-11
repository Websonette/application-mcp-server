<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\Tests\Support;

use PHPUnit\Framework\TestCase;
use Websonette\ApplicationMcpServer\Admin\Administration;
use Websonette\ApplicationMcpServer\Approval\Approvals;
use Websonette\ApplicationMcpServer\Config;
use Websonette\ApplicationMcpServer\Persistence\SchemaMigrator;
use Websonette\ApplicationMcpServer\Persistence\Store;
use Websonette\ApplicationMcpServer\Security\Principal;
use Websonette\ApplicationMcpServer\Tool\Dispatcher;
use Websonette\ApplicationMcpServer\Tool\Registry;

abstract class DatabaseTestCase extends TestCase
{
    protected Store $store;
    protected Config $config;
    protected Administration $admin;
    protected Approvals $approvals;
    protected Registry $tools;
    protected Dispatcher $dispatcher;
    protected Principal $principal;
    protected TestClock $clock;

    protected function setUp(): void
    {
        $this->clock = new TestClock();
        $dsn = getenv('MCP_TEST_DSN') ?: 'sqlite::memory:';
        $pdo = new \PDO($dsn, getenv('MCP_TEST_USER') ?: null, getenv('MCP_TEST_PASSWORD') ?: null);
        $this->store = new Store($pdo, $this->clock);
        (new SchemaMigrator($pdo))->migrate();
        // MCP_TEST_DSN must point to a dedicated disposable test database.
        foreach (['client_metadata', 'sessions', 'rate_limits', 'audit_log', 'idempotency', 'approvals', 'refresh_tokens', 'authorization_codes', 'access_tokens', 'clients'] as $table) {
            $pdo->exec('DELETE FROM mcp_' . $table);
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS test_items (id INTEGER PRIMARY KEY, version INTEGER NOT NULL, title VARCHAR(255) NOT NULL, published INTEGER NOT NULL)');
        $pdo->exec('DELETE FROM test_items');
        $pdo->exec("INSERT INTO test_items VALUES (1, 1, 'Draft', 0)");
        $this->config = new Config('http://127.0.0.1:8080', development: true);
        $this->admin = new Administration($this->store);
        $this->admin->registerClient('test-client', 'Test client', ['http://127.0.0.1:9999/callback'], ['read', 'write', 'publish']);
        $this->principal = new Principal('test-client', 'user-1', ['read', 'write', 'publish']);
        $this->tools = new Registry();
        $this->approvals = new Approvals($this->store);
        $this->dispatcher = new Dispatcher($this->tools, $this->store, $this->approvals);
    }
}
