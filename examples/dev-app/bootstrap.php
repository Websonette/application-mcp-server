<?php
declare(strict_types=1);

use Websonette\ApplicationMcpServer\Config;
use Websonette\ApplicationMcpServer\Console\Context;
use Websonette\ApplicationMcpServer\Persistence\Store;
use Websonette\ApplicationMcpServer\SystemClock;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
if (!is_dir(__DIR__ . '/var')) { throw new RuntimeException('Run php examples/dev-app/setup.php explicitly first.'); }
return new Context(new Config('http://127.0.0.1:8080', name: 'Websonette MCP demo', development: true),
    new Store(new PDO('sqlite:' . __DIR__ . '/var/demo.sqlite'), new SystemClock()));
