<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\OAuth;

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use Websonette\ApplicationMcpServer\Config;
use Websonette\ApplicationMcpServer\Persistence\Store;

final readonly class Repositories implements ClientRepositoryInterface, ScopeRepositoryInterface, AccessTokenRepositoryInterface, AuthCodeRepositoryInterface, RefreshTokenRepositoryInterface
{
    /** @param list<non-empty-string> $scopes */
    public function __construct(private Store $store, private Config $config, public array $scopes)
    {
    }

    public function getClientEntity(string $clientIdentifier): ?ClientEntityInterface
    {
        $row = $this->store->row('SELECT * FROM mcp_clients WHERE id = :id AND revoked = 0', ['id' => $clientIdentifier]);
        if ($row === null || $clientIdentifier === '') { return null; }
        /** @var list<string> $redirects */
        $redirects = json_decode((string) $row['redirect_uris'], true, 32, JSON_THROW_ON_ERROR);
        return new Client($clientIdentifier, (string) $row['name'], $redirects, (bool) $row['confidential']);
    }

    public function validateClient(string $clientIdentifier, ?string $clientSecret, ?string $grantType): bool
    {
        $row = $this->store->row('SELECT * FROM mcp_clients WHERE id = :id AND revoked = 0', ['id' => $clientIdentifier]);
        return $row !== null && (!(bool) $row['confidential']
            || ($clientSecret !== null && password_verify($clientSecret, (string) $row['secret_hash'])));
    }

    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface
    {
        return in_array($identifier, $this->scopes, true) ? new Scope($identifier) : null;
    }

    /**
     * @param ScopeEntityInterface[] $scopes
     * @return ScopeEntityInterface[]
     */
    public function finalizeScopes(array $scopes, string $grantType, ClientEntityInterface $clientEntity, ?string $userIdentifier = null, ?string $authCodeId = null): array
    {
        $row = $this->store->row('SELECT scopes FROM mcp_clients WHERE id = :id AND revoked = 0', ['id' => $clientEntity->getIdentifier()]);
        $allowed = $row === null ? [] : json_decode((string) $row['scopes'], true, 32, JSON_THROW_ON_ERROR);
        foreach ($scopes as $scope) {
            if (!in_array($scope->getIdentifier(), $allowed, true)) {
                throw OAuthServerException::invalidScope($scope->getIdentifier());
            }
        }
        return $scopes;
    }

    /** @param ScopeEntityInterface[] $scopes */
    public function getNewToken(ClientEntityInterface $clientEntity, array $scopes, ?string $userIdentifier = null): AccessTokenEntityInterface
    {
        $token = new AccessToken($this->config->url('/mcp'), rtrim($this->config->baseUrl, '/'));
        $token->setClient($clientEntity);
        if ($userIdentifier !== null && $userIdentifier !== '') { $token->setUserIdentifier($userIdentifier); }
        foreach ($scopes as $scope) { $token->addScope($scope); }
        return $token;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $this->store->execute('INSERT INTO mcp_access_tokens (id, client_id, user_id, scopes, expires_at, revoked) VALUES (:id, :client, :user, :scopes, :expires, 0)',
            ['id' => hash('sha256', $accessTokenEntity->getIdentifier()), 'client' => $accessTokenEntity->getClient()->getIdentifier(),
                'user' => $accessTokenEntity->getUserIdentifier(),
                'scopes' => json_encode(array_map(static fn (ScopeEntityInterface $s): string => $s->getIdentifier(), $accessTokenEntity->getScopes()), JSON_THROW_ON_ERROR),
                'expires' => $accessTokenEntity->getExpiryDateTime()->getTimestamp()]);
        $this->store->audit(new \Websonette\ApplicationMcpServer\Security\Principal($accessTokenEntity->getClient()->getIdentifier(), $accessTokenEntity->getUserIdentifier(), []),
            'oauth_token_issue', 'success', bin2hex(random_bytes(16)));
    }

    public function revokeAccessToken(string $tokenId): void
    {
        $this->store->execute('UPDATE mcp_access_tokens SET revoked = 1 WHERE id = :id', ['id' => hash('sha256', $tokenId)]);
    }

    public function isAccessTokenRevoked(string $tokenId): bool
    {
        $row = $this->store->row('SELECT t.id FROM mcp_access_tokens t JOIN mcp_clients c ON c.id = t.client_id WHERE t.id = :id AND t.revoked = 0 AND c.revoked = 0 AND t.expires_at > :now',
            ['id' => hash('sha256', $tokenId), 'now' => $this->store->now()]);
        return $row === null;
    }

    public function getNewAuthCode(): AuthCodeEntityInterface { return new AuthCode(); }

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        $this->store->execute('INSERT INTO mcp_authorization_codes (id, client_id, expires_at, revoked) VALUES (:id, :client, :expires, 0)',
            ['id' => hash('sha256', $authCodeEntity->getIdentifier()), 'client' => $authCodeEntity->getClient()->getIdentifier(),
                'expires' => $authCodeEntity->getExpiryDateTime()->getTimestamp()]);
        $this->store->audit(new \Websonette\ApplicationMcpServer\Security\Principal($authCodeEntity->getClient()->getIdentifier(), $authCodeEntity->getUserIdentifier(), []),
            'oauth_consent', 'approved', bin2hex(random_bytes(16)));
    }

    public function revokeAuthCode(string $codeId): void
    {
        $this->store->execute('UPDATE mcp_authorization_codes SET revoked = 1 WHERE id = :id', ['id' => hash('sha256', $codeId)]);
    }

    public function isAuthCodeRevoked(string $codeId): bool
    {
        // Lock until validation and issuance finish; a bad PKCE verifier must not consume the code.
        $row = $this->lockedRow('SELECT revoked, expires_at FROM mcp_authorization_codes WHERE id = :id', hash('sha256', $codeId));
        return $row === null || (bool) $row['revoked'] || (int) $row['expires_at'] <= $this->store->now();
    }

    public function getNewRefreshToken(): RefreshTokenEntityInterface { return new RefreshToken(); }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        $this->store->execute('INSERT INTO mcp_refresh_tokens (id, access_token_id, expires_at, revoked) VALUES (:id, :access, :expires, 0)',
            ['id' => hash('sha256', $refreshTokenEntity->getIdentifier()),
                'access' => hash('sha256', $refreshTokenEntity->getAccessToken()->getIdentifier()),
                'expires' => $refreshTokenEntity->getExpiryDateTime()->getTimestamp()]);
    }

    public function revokeRefreshToken(string $tokenId): void
    {
        $this->store->execute('UPDATE mcp_refresh_tokens SET revoked = 1 WHERE id = :id', ['id' => hash('sha256', $tokenId)]);
    }

    public function isRefreshTokenRevoked(string $tokenId): bool
    {
        $id = hash('sha256', $tokenId);
        $token = $this->lockedRow('SELECT revoked, expires_at FROM mcp_refresh_tokens WHERE id = :id', $id);
        if ($token !== null && !(bool) $token['revoked'] && (int) $token['expires_at'] > $this->store->now()) {
            return false;
        }
        $row = $this->store->row('SELECT a.client_id FROM mcp_refresh_tokens r JOIN mcp_access_tokens a ON a.id = r.access_token_id WHERE r.id = :id', ['id' => $id]);
        if ($row !== null) {
            // Conservative replay response: invalidate all grants for this client.
            $this->store->audit(new \Websonette\ApplicationMcpServer\Security\Principal((string) $row['client_id'], null, []),
                'oauth_refresh_replay', 'revoked', bin2hex(random_bytes(16)));
            $this->store->execute('UPDATE mcp_access_tokens SET revoked = 1 WHERE client_id = :client', ['client' => (string) $row['client_id']]);
            $this->store->execute('UPDATE mcp_refresh_tokens SET revoked = 1 WHERE access_token_id IN (SELECT id FROM mcp_access_tokens WHERE client_id = :client)', ['client' => (string) $row['client_id']]);
        }
        return true;
    }

    /** @return array<string, mixed>|null */
    private function lockedRow(string $sql, string $id): ?array
    {
        if (!$this->store->pdo->inTransaction()) {
            throw new \LogicException('OAuth redemption must run inside the server transaction.');
        }
        if ($this->store->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') { $sql .= ' FOR UPDATE'; }
        return $this->store->row($sql, ['id' => $id]);
    }
}
