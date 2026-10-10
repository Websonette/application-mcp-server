<?php
declare(strict_types=1);
use Websonette\ApplicationMcpServer\Console\Context;
use Websonette\ApplicationMcpServer\Console\KeyGenerator;
use Websonette\ApplicationMcpServer\Persistence\SchemaMigrator;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!is_dir(__DIR__ . '/var')) { mkdir(__DIR__ . '/var', 0700, true); }
/** @var Context $context */
$context = require __DIR__ . '/bootstrap.php';
(new SchemaMigrator($context->store->pdo))->migrate();
$context->store->execute('CREATE TABLE IF NOT EXISTS demo_properties (id INTEGER PRIMARY KEY, version INTEGER NOT NULL, title TEXT NOT NULL, published INTEGER NOT NULL)');
$context->store->execute("INSERT OR IGNORE INTO demo_properties VALUES (1, 1, 'Example apartment draft', 0)");
if ($context->store->row("SELECT id FROM mcp_clients WHERE id = 'demo-client'") === null) {
    $context->admin->registerClient('demo-client', 'Demo desktop client', ['http://127.0.0.1:9999/callback'], ['project:read', 'properties:read', 'properties:write', 'properties:publish']);
}
if (!is_file(__DIR__ . '/var/keys/private.pem')) { (new KeyGenerator())->generate(__DIR__ . '/var/keys'); }
echo "Demo initialized. No production data is used.\n";
echo "Start with MCP_DEMO_PASSWORD set to a unique local password and php -S 127.0.0.1:8080 examples/dev-app/router.php\n";
echo "For a development bearer token, explicitly run token:issue --bootstrap=examples/dev-app/bootstrap.php --id=demo-client --user=demo-user --scopes=project:read,properties:read,properties:write,properties:publish\n";
