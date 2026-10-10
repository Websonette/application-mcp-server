<?php
declare(strict_types=1);
// Smoke test for a manually started loopback demo server. Prints no bearer/approval credentials.
/**
 * @param non-empty-string $method
 * @param array<string, string>|null $form
 * @param list<string> $headers
 * @return array{int, array<string, string>, string}
 */
function request(string $path, string $method = 'GET', ?array $form = null, array $headers = []): array
{
    /** @var string $cookie */
    static $cookie = '';
    $curl = curl_init('http://127.0.0.1:8080' . $path);
    $responseHeaders = [];
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => static function (CurlHandle $handle, string $line) use (&$responseHeaders, &$cookie): int {
            $length = strlen($line);
            $parts = explode(':', trim($line), 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower($parts[0])] = trim($parts[1]);
                if (strtolower($parts[0]) === 'set-cookie') { $cookie = explode(';', trim($parts[1]))[0]; }
            }
            return $length;
        },
    ]);
    if ($cookie !== '') { curl_setopt($curl, CURLOPT_COOKIE, $cookie); }
    if ($form !== null) { curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($form)); }
    $body = curl_exec($curl);
    if (!is_string($body)) { throw new RuntimeException('HTTP smoke test failed.'); }
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    return [$status, $responseHeaders, $body];
}
$verifier = str_repeat('x', 64);
$params = [
    'response_type' => 'code', 'client_id' => 'demo-client', 'redirect_uri' => 'http://127.0.0.1:9999/callback',
    'scope' => 'project:read properties:read', 'state' => 'smoke-state',
    'code_challenge_method' => 'S256',
    'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
    'resource' => 'http://127.0.0.1:8080/mcp',
];
$authorize = '/oauth/authorize?' . http_build_query($params);
[$status, $headers, $body] = request($authorize);
if ($status !== 200 || !preg_match('/name="csrf" value="([a-f0-9]{64})"/', $body, $match)) { throw new RuntimeException('Consent page failed.'); }
[$denied] = request($authorize, 'POST', ['csrf' => 'invalid', 'password' => 'test-only-demo-password-123', 'approve' => 'yes']);
if ($denied !== 403) { throw new RuntimeException('CSRF rejection failed.'); }
[$status, $headers] = request($authorize, 'POST', ['csrf' => $match[1], 'password' => 'test-only-demo-password-123', 'approve' => 'yes']);
if ($status !== 302) { throw new RuntimeException('Human consent failed.'); }
parse_str((string) parse_url($headers['location'], PHP_URL_QUERY), $callback);
if (!is_string($callback['code'] ?? null)) { throw new RuntimeException('Authorization code missing.'); }
if (($callback['state'] ?? null) !== 'smoke-state') { throw new RuntimeException('State echo failed.'); }
[$status, $headers, $body] = request('/oauth/token', 'POST', [
    'grant_type' => 'authorization_code', 'client_id' => 'demo-client', 'redirect_uri' => $params['redirect_uri'],
    'code' => $callback['code'], 'code_verifier' => $verifier, 'resource' => $params['resource'],
]);
if ($status !== 200) { throw new RuntimeException('Token exchange failed.'); }
$token = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
$curl = curl_init('http://127.0.0.1:8080/mcp');
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token['access_token'], 'Content-Type: application/json',
        'Accept: application/json, text/event-stream', 'MCP-Protocol-Version: 2026-07-28', 'Mcp-Method: tools/call', 'Mcp-Name: properties_get'],
    CURLOPT_POSTFIELDS => json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [
        'name' => 'properties_get', 'arguments' => ['id' => 1], '_meta' => [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => new stdClass(),
            'io.modelcontextprotocol/clientInfo' => ['name' => 'smoke-test', 'version' => '1'],
        ],
    ]], JSON_THROW_ON_ERROR),
]);
$response = curl_exec($curl);
if (!is_string($response) || curl_getinfo($curl, CURLINFO_RESPONSE_CODE) !== 200) { throw new RuntimeException('MCP call failed.'); }
$result = json_decode($response, true, 32, JSON_THROW_ON_ERROR);
if (($result['result']['structuredContent']['title'] ?? null) !== 'Example apartment draft') { throw new RuntimeException('MCP business result failed.'); }
echo "Demo smoke passed: consent, CSRF rejection, PKCE exchange, resource-bound bearer and real HTTP MCP call.\n";
