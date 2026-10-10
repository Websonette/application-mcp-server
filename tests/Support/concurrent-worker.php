<?php
declare(strict_types=1);

use Websonette\ApplicationMcpServer\Approval\Approvals;
use Websonette\ApplicationMcpServer\Persistence\Store;
use Websonette\ApplicationMcpServer\Security\Principal;
use Websonette\ApplicationMcpServer\SystemClock;
use Websonette\ApplicationMcpServer\Tool\Definition;
use Websonette\ApplicationMcpServer\Tool\Dispatcher;
use Websonette\ApplicationMcpServer\Tool\Registry;
use Websonette\ApplicationMcpServer\Tool\Risk;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!isset($argv) || count($argv) !== 5) { fwrite(STDERR, "Expected worker arguments.\n"); exit(2); }
[$script, $dsn, $barrier, $client, $counter] = $argv;
$pdo = new PDO($dsn, getenv('MCP_TEST_USER') ?: null, getenv('MCP_TEST_PASSWORD') ?: null);
if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') { $pdo->exec('PRAGMA busy_timeout = 5000'); }
$store = new Store($pdo, new SystemClock());
$tools = new Registry();
$tools->add(new Definition('increment', 'Increment once.', ['type' => 'object'], ['write'], Risk::Write,
    static function (array $args, Principal $principal) use ($store, $counter): array {
        $store->execute('UPDATE test_concurrent_counter SET value = value + 1 WHERE id = :id', ['id' => $counter]);
        usleep(150000);
        return ['value' => (int) ($store->row('SELECT value FROM test_concurrent_counter WHERE id = :id', ['id' => $counter])['value'] ?? -1)];
    }));
$dispatcher = new Dispatcher($tools, $store, new Approvals($store));
$deadline = microtime(true) + 10;
while (!is_file($barrier) && microtime(true) < $deadline) { usleep(10000); }
if (!is_file($barrier)) { exit(2); }
for ($attempt = 0; $attempt < 5; $attempt++) {
    try {
        $result = $dispatcher->call('increment', ['idempotency_key' => 'same-operation'], new Principal($client, 'test-user', ['write']), 'worker');
        echo json_encode($result, JSON_THROW_ON_ERROR);
        exit(0);
    } catch (PDOException) {
        // Concurrent SQLite snapshots / MySQL deadlocks are retryable, not duplicate side effects.
        usleep(100000);
    }
}
fwrite(STDERR, "Concurrent operation could not be completed.\n");
exit(1);
