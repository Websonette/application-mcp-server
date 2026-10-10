<?php
declare(strict_types=1);
use Websonette\ApplicationMcpServer\Approval\Approvals;
use Websonette\ApplicationMcpServer\Console\Context;
use Websonette\ApplicationMcpServer\Content\Catalog;
use Websonette\ApplicationMcpServer\Http\Handler;
use Websonette\ApplicationMcpServer\OAuth\BearerAuthenticator;
use Websonette\ApplicationMcpServer\OAuth\Repositories;
use Websonette\ApplicationMcpServer\OAuth\Server;
use Websonette\ApplicationMcpServer\Tool\Dispatcher;
use Websonette\ApplicationMcpServer\Tool\Registry;

require_once __DIR__ . '/DemoTools.php';
require_once __DIR__ . '/DemoConsent.php';
/** @var Context $context */
$context = require __DIR__ . '/bootstrap.php';
$scopes = ['project:read', 'properties:read', 'properties:write', 'properties:publish'];
$keys = __DIR__ . '/var/keys';
if (!is_file($keys . '/encryption.key')) { throw new RuntimeException('Run the explicit demo setup first.'); }
$oauth = new Server($context->config, $context->store, new Repositories($context->store, $context->config, $scopes),
    $keys . '/private.pem', $keys . '/public.pem', trim((string) file_get_contents($keys . '/encryption.key')), new DemoConsent());
$auth = new BearerAuthenticator($oauth->resource, $context->store, $context->config, $context->tokens);
$tools = new Registry();
$content = new Catalog();
(new DemoTools($context->store))->register($tools, $content);
return new Handler($context->config, $context->store, $auth, $tools,
    new Dispatcher($tools, $context->store, new Approvals($context->store)), $content, $scopes, $oauth);
