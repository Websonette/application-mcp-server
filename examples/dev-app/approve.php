<?php
declare(strict_types=1);
use Websonette\ApplicationMcpServer\Approval\Approvals;
use Websonette\ApplicationMcpServer\Console\Context;
use Websonette\ApplicationMcpServer\Security\Principal;

/** Local operator CLI only. Production approval must use the application's authorized CSRF-protected UI. */
/** @var Context $context */
$context = require __DIR__ . '/bootstrap.php';
if (!$context->config->development) { throw new LogicException('Demo approval is development-only.'); }
$property = $context->store->row('SELECT * FROM demo_properties WHERE id = 1');
if ($property === null || (bool) $property['published']) { throw new RuntimeException('No unpublished demo property available.'); }
echo 'Publish demo property "' . $property['title'] . '" at version ' . $property['version'] . " for demo-client/demo-user?\n";
echo "Type APPROVE to issue a five-minute ticket for these exact parameters: ";
if (trim((string) fgets(STDIN)) !== 'APPROVE') { echo "Cancelled.\n"; exit(1); }
$ticket = (new Approvals($context->store))->issue(new Principal('demo-client', 'demo-user', ['properties:publish']),
    'properties_publish', ['id' => 1, 'expected_version' => (int) $property['version']], 'local-demo-operator');
echo "Single-use approval ticket (do not log it): " . $ticket . "\n";
