<?php
declare(strict_types=1);

use Websonette\ApplicationMcpServer\Config;
use Websonette\ApplicationMcpServer\Console\Context;
use Websonette\ApplicationMcpServer\Persistence\Store;
use Websonette\ApplicationMcpServer\SystemClock;

$dsn = getenv('MCP_CLI_TEST_DSN');
if (!is_string($dsn) || $dsn === '') { throw new RuntimeException('Test DSN required.'); }
return new Context(new Config('http://127.0.0.1:8080', development: true), new Store(new PDO($dsn), new SystemClock()));
