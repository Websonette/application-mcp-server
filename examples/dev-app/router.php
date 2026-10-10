<?php
declare(strict_types=1);
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Websonette\ApplicationMcpServer\Http\Handler;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
// Explicit loopback development adapter. A production adapter must use trusted proxy settings.
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => false]);
session_start();
try {
    $headers = getallheaders();
    $uri = 'http://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/');
    $request = new ServerRequest($_SERVER['REQUEST_METHOD'] ?? 'GET', $uri, $headers, fopen('php://input', 'r'));
    $request = $request->withQueryParams($_GET)->withParsedBody($_POST === [] ? null : $_POST);
    /** @var Handler $app */
    $app = require __DIR__ . '/app.php';
    $response = $app->handle($request);
} catch (Throwable) {
    $response = new Response(500, ['Content-Type' => 'application/json'], '{"error":"demo_unavailable","message":"Check explicit setup and server-side configuration."}');
}
session_write_close();
http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    foreach ($values as $value) { header($name . ': ' . $value, false); }
}
echo (string) $response->getBody();
