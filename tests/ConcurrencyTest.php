<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\Tests;

use PHPUnit\Framework\TestCase;
use Websonette\ApplicationMcpServer\Admin\Administration;
use Websonette\ApplicationMcpServer\Persistence\SchemaMigrator;
use Websonette\ApplicationMcpServer\Persistence\Store;
use Websonette\ApplicationMcpServer\SystemClock;

final class ConcurrencyTest extends TestCase
{
    public function testSeparatePhpWorkersDoNotDuplicateAWrite(): void
    {
        $directory = sys_get_temp_dir() . '/websonette-concurrency-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $dsn = getenv('MCP_TEST_DSN') ?: 'sqlite:' . $directory . '/test.sqlite';
        $pdo = new \PDO($dsn, getenv('MCP_TEST_USER') ?: null, getenv('MCP_TEST_PASSWORD') ?: null);
        $store = new Store($pdo, new SystemClock());
        (new SchemaMigrator($pdo))->migrate();
        $id = bin2hex(random_bytes(16));
        $client = 'concurrent-' . $id;
        (new Administration($store))->registerClient($client, 'Concurrent test', [], ['write']);
        $store->execute('CREATE TABLE IF NOT EXISTS test_concurrent_counter (id VARCHAR(32) PRIMARY KEY, value INTEGER NOT NULL)');
        $store->execute('INSERT INTO test_concurrent_counter (id, value) VALUES (:id, 0)', ['id' => $id]);
        $processes = [];
        try {
            for ($i = 0; $i < 3; $i++) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, __DIR__ . '/Support/concurrent-worker.php', $dsn, $directory . '/go', $client, $id],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = [$process, $pipes[1], $pipes[2]];
            }
            touch($directory . '/go');
            foreach ($processes as [$process, $stdout, $stderr]) {
                $result = stream_get_contents($stdout);
                $error = stream_get_contents($stderr);
                fclose($stdout);
                fclose($stderr);
                self::assertSame(0, proc_close($process), (string) $error);
                self::assertSame(['value' => 1], json_decode((string) $result, true, 32, JSON_THROW_ON_ERROR));
            }
            self::assertSame(1, (int) ($store->row('SELECT value FROM test_concurrent_counter WHERE id = :id', ['id' => $id])['value'] ?? -1));
        } finally {
            if (is_file($directory . '/go')) { unlink($directory . '/go'); }
            unset($store, $pdo);
            if (is_file($directory . '/test.sqlite')) { unlink($directory . '/test.sqlite'); }
            rmdir($directory);
        }
    }
}
