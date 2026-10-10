<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\Security;

use Websonette\ApplicationMcpServer\Config;
use Websonette\ApplicationMcpServer\Persistence\Store;

final readonly class Tokens implements Authenticator
{
    public function __construct(private Store $store, private Config $config) {}

    /** @param list<string> $scopes */
    public function issue(string $clientId, array $scopes, ?string $userId = null, int $ttl = 3600): string
    {
        if (!$this->config->development || $ttl < 1 || $ttl > 86400) {
            throw new \LogicException('Manual tokens are development-only and expire within one day.');
        }
        $this->store->assertClientActive($clientId);
        $client = $this->store->row('SELECT scopes FROM mcp_clients WHERE id = :id', ['id' => $clientId]);
        if ($client === null) { throw new AuthenticationException('Client is no longer valid.'); }
        /** @var list<string> $allowed */
        $allowed = json_decode((string) $client['scopes'], true, 32, JSON_THROW_ON_ERROR);
        if (array_diff($scopes, $allowed) !== []) { throw new \InvalidArgumentException('Scopes exceed client permissions.'); }
        $token = bin2hex(random_bytes(32));
        $this->store->execute('INSERT INTO mcp_access_tokens (id, client_id, user_id, scopes, expires_at, revoked) VALUES (:id, :client, :user, :scopes, :expires, 0)',
            ['id' => hash('sha256', $token), 'client' => $clientId, 'user' => $userId, 'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR), 'expires' => $this->store->now() + $ttl]);
        return $token;
    }

    public function authenticate(\Psr\Http\Message\ServerRequestInterface $request): Principal
    {
        $authorization = $request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/iD', $authorization, $matches)) {
            throw new AuthenticationException('Invalid bearer token.');
        }
        $row = $this->store->row('SELECT t.* FROM mcp_access_tokens t JOIN mcp_clients c ON c.id = t.client_id WHERE t.id = :id AND t.revoked = 0 AND c.revoked = 0 AND t.expires_at > :now',
            ['id' => hash('sha256', $matches[1]), 'now' => $this->store->now()]);
        if (!$this->config->development || $row === null) { throw new AuthenticationException('Invalid bearer token.'); }
        /** @var list<string> $scopes */
        $scopes = json_decode((string) $row['scopes'], true, 32, JSON_THROW_ON_ERROR);
        return new Principal((string) $row['client_id'], $row['user_id'] === null ? null : (string) $row['user_id'], $scopes);
    }
}
