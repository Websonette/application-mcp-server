<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\OAuth;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Websonette\ApplicationMcpServer\Admin\Administration;
use Websonette\ApplicationMcpServer\Persistence\Store;
use Websonette\ApplicationMcpServer\Security\ScopeCatalog;

final readonly class ClientRegistration
{
    public function __construct(private Store $store, private ScopeCatalog $scopes, private Administration $admin,
        private MetadataFetcher $fetcher, public bool $cimd = true, public bool $dcr = false) {}

    public function register(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->dcr) { return $this->json(404, ['error' => 'not_found']); }
        if ($request->getMethod() !== 'POST') { return new Response(405, ['Allow' => 'POST']); }
        if (!$this->quota($request)) { return $this->json(429, ['error' => 'registration_rate_limited'])->withHeader('Retry-After', '60'); }
        if (strtolower(explode(';', $request->getHeaderLine('Content-Type'))[0]) !== 'application/json') {
            return $this->json(415, ['error' => 'invalid_client_metadata']);
        }
        try {
            $document = json_decode((string) $request->getBody(), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($document) || array_is_list($document)) { throw new \InvalidArgumentException('JSON object required.'); }
            $method = $document['token_endpoint_auth_method'] ?? 'client_secret_basic';
            if (!in_array($method, ['none', 'client_secret_basic', 'client_secret_post'], true)) { throw new \InvalidArgumentException('Unsupported token authentication.'); }
            [$name, $redirects, $scopes] = $this->metadata($document);
            $applicationType = $document['application_type'] ?? 'web';
            $id = bin2hex(random_bytes(24));
            $secret = $method === 'none' ? null : bin2hex(random_bytes(32));
            $this->admin->registerClient($id, $name, $redirects, $scopes, $secret, 'dynamic-registration', function () use ($id, $method, $applicationType): void {
                $this->store->execute('INSERT INTO mcp_client_metadata (client_id, expires_at, auth_method, application_type) VALUES (:id, 0, :method, :type)',
                    ['id' => $id, 'method' => $method, 'type' => $applicationType]);
            });
            $result = ['client_id' => $id, 'client_id_issued_at' => $this->store->now(), 'client_name' => $name,
                'redirect_uris' => $redirects, 'scope' => implode(' ', $scopes), 'grant_types' => ['authorization_code', 'refresh_token'],
                'response_types' => ['code'], 'token_endpoint_auth_method' => $method, 'application_type' => $applicationType];
            if ($secret !== null) { $result['client_secret'] = $secret; $result['client_secret_expires_at'] = 0; }
            return $this->json(201, $result);
        } catch (\InvalidArgumentException|\JsonException) {
            return $this->json(400, ['error' => 'invalid_client_metadata']);
        }
    }

    public function resolveCimd(string $id, ServerRequestInterface $request): void
    {
        if (!$this->cimd || !str_starts_with($id, 'https://')) { return; }
        $existing = $this->store->row('SELECT revoked FROM mcp_clients WHERE id = :id', ['id' => $id]);
        if ($existing !== null && (bool) $existing['revoked']) { throw new \InvalidArgumentException('Client revoked.'); }
        $cache = $this->store->row('SELECT expires_at FROM mcp_client_metadata WHERE client_id = :id', ['id' => $id]);
        // Explicit pre-registration is authoritative; do not overwrite it with fetched metadata.
        if ($existing !== null && $cache === null) { return; }
        if ($cache !== null && (int) $cache['expires_at'] > $this->store->now()) { return; }
        if (!$this->quota($request, $existing === null)) { throw new \InvalidArgumentException('Client resolution quota exceeded.'); }
        $document = $this->fetcher->fetch($id);
        if (($document['client_id'] ?? null) !== $id) { throw new \InvalidArgumentException('Metadata client_id mismatch.'); }
        $methods = $document['token_endpoint_auth_methods_supported'] ?? [$document['token_endpoint_auth_method'] ?? 'none'];
        if (!is_array($methods) || !in_array('none', $methods, true)) { throw new \InvalidArgumentException('This server supports public CIMD clients with PKCE.'); }
        [$name, $redirects, $scopes] = $this->metadata($document);
        $applicationType = $document['application_type'] ?? 'web';
        if ($existing === null) {
            $this->admin->registerClient($id, $name, $redirects, $scopes, null, 'client-metadata', function () use ($id, $applicationType): void {
                $this->store->execute('INSERT INTO mcp_client_metadata (client_id, expires_at, application_type) VALUES (:id, :expires, :type)',
                    ['id' => $id, 'expires' => $this->store->now() + 3600, 'type' => $applicationType]);
            });
        } else {
            $this->store->transaction(function () use ($id, $name, $redirects, $scopes, $applicationType): void {
                $this->store->execute('UPDATE mcp_clients SET name = :name, redirect_uris = :redirects, scopes = :scopes WHERE id = :id AND revoked = 0',
                    ['id' => $id, 'name' => $name, 'redirects' => json_encode($redirects, JSON_THROW_ON_ERROR), 'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR)]);
                $this->store->execute('UPDATE mcp_client_metadata SET expires_at = :expires, application_type = :type WHERE client_id = :id',
                    ['id' => $id, 'expires' => $this->store->now() + 3600, 'type' => $applicationType]);
            });
        }
    }
    private function quota(ServerRequestInterface $request, bool $new = true): bool
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
        $row = $this->store->row('SELECT COUNT(*) AS count FROM mcp_clients');
        return (!$new || (int) ($row['count'] ?? 0) < 10000) && $this->store->hit('client_registration', is_string($ip) ? $ip : 'unknown', 10)
            && $this->store->hit('client_registration_global', 'all', 100);
    }
    /**
     * @param array<array-key, mixed> $document
     * @return array{string, list<string>, list<string>}
     */
    private function metadata(array $document): array
    {
        $name = $document['client_name'] ?? 'MCP client';
        if (!in_array($document['application_type'] ?? 'web', ['web', 'native'], true)) { throw new \InvalidArgumentException('Invalid application type.'); }
        $redirects = $document['redirect_uris'] ?? null;
        if (!is_string($name) || $name === '' || strlen($name) > 255 || !is_array($redirects) || !array_is_list($redirects)
            || count($redirects) < 1 || count($redirects) > 10) { throw new \InvalidArgumentException('Invalid client metadata.'); }
        foreach ($redirects as $uri) { if (!is_string($uri) || strlen($uri) > 2048) { throw new \InvalidArgumentException('Invalid redirects.'); } }
        Administration::validateRedirectUris($redirects);
        if (($document['application_type'] ?? 'web') === 'web') {
            foreach ($redirects as $uri) {
                if (!str_starts_with($uri, 'https://')) { throw new \InvalidArgumentException('Web clients require HTTPS callbacks.'); }
            }
        }
        foreach (['grant_types' => ['authorization_code', 'refresh_token'], 'response_types' => ['code']] as $field => $supported) {
            if (isset($document[$field])) {
                if (!is_array($document[$field]) || !array_is_list($document[$field]) || $document[$field] === []) {
                    throw new \InvalidArgumentException('Unsupported OAuth flow.');
                }
                foreach ($document[$field] as $value) {
                    if (!is_string($value) || !in_array($value, $supported, true)) { throw new \InvalidArgumentException('Unsupported OAuth flow.'); }
                }
            }
        }
        $scope = $document['scope'] ?? null;
        if ($scope !== null && !is_string($scope)) { throw new \InvalidArgumentException('Invalid scope.'); }
        $scopes = $scope === null ? $this->scopes->identifiers() : array_values(array_filter(explode(' ', $scope), static fn (string $s): bool => $s !== ''));
        $this->scopes->validate($scopes);
        if ($scopes === []) { throw new \InvalidArgumentException('No scopes.'); }
        return [$name, $redirects, array_values(array_unique($scopes))];
    }
    /** @param array<string, mixed> $body */
    private function json(int $status, array $body): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'], json_encode($body, JSON_THROW_ON_ERROR));
    }
}
