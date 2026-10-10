<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\Admin;

use Websonette\ApplicationMcpServer\Persistence\Store;
use Websonette\ApplicationMcpServer\Security\Principal;
use Websonette\ApplicationMcpServer\Security\Scopes;

final readonly class Administration
{
    public function __construct(private Store $store) {}

    /**
     * @param list<string> $redirectUris
     * @param list<string> $scopes
     */
    public function registerClient(string $id, string $name, array $redirectUris, array $scopes, #[\SensitiveParameter] ?string $secret = null, string $actorId = 'operator'): void
    {
        if ($id === '' || strlen($id) > 128 || $name === '' || $scopes === []) {
            throw new \InvalidArgumentException('Client identifier, name and scopes are required.');
        }
        Scopes::validate($scopes);
        if ($secret !== null && strlen($secret) < 32) { throw new \InvalidArgumentException('Client secrets must contain at least 32 bytes.'); }
        foreach ($redirectUris as $uri) {
            $p = parse_url($uri);
            if ($p === false || isset($p['fragment']) || isset($p['user']) || isset($p['pass'])
                || !isset($p['host']) || !(($p['scheme'] ?? '') === 'https'
                    || (($p['scheme'] ?? '') === 'http' && in_array($p['host'], ['127.0.0.1', '[::1]', 'localhost'], true)))) {
                throw new \InvalidArgumentException('Redirects must be exact HTTPS URLs or loopback HTTP URLs.');
            }
        }
        $this->store->transaction(function () use ($id, $name, $redirectUris, $scopes, $secret, $actorId): void {
            $this->store->execute('INSERT INTO mcp_clients (id, name, redirect_uris, scopes, secret_hash, confidential, revoked, created_at) VALUES (:id, :name, :redirects, :scopes, :secret, :confidential, 0, :time)',
                ['id' => $id, 'name' => $name, 'redirects' => json_encode($redirectUris, JSON_THROW_ON_ERROR),
                    'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR), 'secret' => $secret === null ? null : password_hash($secret, PASSWORD_DEFAULT),
                    'confidential' => $secret === null ? 0 : 1, 'time' => $this->store->now()]);
            $this->store->audit(new Principal($id, $actorId, []), 'client_register', 'success', bin2hex(random_bytes(16)));
        });
    }

    public function disconnectClient(string $clientId, string $actorId = 'operator'): void
    {
        $this->store->transaction(function () use ($clientId, $actorId): void {
            $this->store->execute('UPDATE mcp_clients SET revoked = 1 WHERE id = :id', ['id' => $clientId]);
            $this->store->execute('UPDATE mcp_access_tokens SET revoked = 1 WHERE client_id = :id', ['id' => $clientId]);
            $this->store->execute('UPDATE mcp_refresh_tokens SET revoked = 1 WHERE access_token_id IN (SELECT id FROM mcp_access_tokens WHERE client_id = :id)', ['id' => $clientId]);
            $this->store->execute('UPDATE mcp_authorization_codes SET revoked = 1 WHERE client_id = :id', ['id' => $clientId]);
            $this->store->audit(new Principal($clientId, $actorId, []), 'client_disconnect', 'success', bin2hex(random_bytes(16)));
        });
    }

    /** Accepts the stored identifier hash from the admin listing, never a bearer credential. */
    public function revokeToken(string $storedTokenId, string $actorId = 'operator'): void
    {
        $this->store->transaction(function () use ($storedTokenId, $actorId): void {
            $token = $this->store->row('SELECT client_id FROM mcp_access_tokens WHERE id = :id', ['id' => $storedTokenId]);
            $this->store->execute('UPDATE mcp_access_tokens SET revoked = 1 WHERE id = :id', ['id' => $storedTokenId]);
            $this->store->execute('UPDATE mcp_refresh_tokens SET revoked = 1 WHERE access_token_id = :id', ['id' => $storedTokenId]);
            if ($token !== null) {
                $this->store->audit(new Principal((string) $token['client_id'], $actorId, []), 'token_revoke', 'success', bin2hex(random_bytes(16)));
            }
        });
    }

    /** @return list<array<string, mixed>> */
    public function clients(): array
    {
        $stmt = $this->store->pdo->prepare('SELECT c.id, c.name, c.scopes, c.revoked, c.created_at, COUNT(a.id) AS calls, MAX(a.occurred_at) AS last_activity FROM mcp_clients c LEFT JOIN mcp_audit_log a ON a.client_id = c.id GROUP BY c.id, c.name, c.scopes, c.revoked, c.created_at ORDER BY c.created_at DESC');
        $stmt->execute();
        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public function tokens(string $clientId): array
    {
        $stmt = $this->store->pdo->prepare('SELECT id, user_id, scopes, expires_at, revoked FROM mcp_access_tokens WHERE client_id = :id ORDER BY expires_at DESC');
        $stmt->execute(['id' => $clientId]);
        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    public function activity(int $limit = 40): array { return $this->store->recentActivity($limit); }
}
