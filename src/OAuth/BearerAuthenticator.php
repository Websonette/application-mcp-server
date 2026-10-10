<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\OAuth;

use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Token\Parser;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Psr\Http\Message\ServerRequestInterface;
use Websonette\ApplicationMcpServer\Config;
use Websonette\ApplicationMcpServer\Persistence\Store;
use Websonette\ApplicationMcpServer\Security\AuthenticationException;
use Websonette\ApplicationMcpServer\Security\Authenticator;
use Websonette\ApplicationMcpServer\Security\Principal;
use Websonette\ApplicationMcpServer\Security\Tokens;

final readonly class BearerAuthenticator implements Authenticator
{
    public function __construct(private ResourceServer $server, private Store $store, private Config $config, private Tokens $tokens) {}

    public function authenticate(ServerRequestInterface $request): Principal
    {
        if ($this->config->development && preg_match('/^Bearer [a-f0-9]{64}$/iD', $request->getHeaderLine('Authorization'))) {
            return $this->tokens->authenticate($request);
        }
        try {
            $validated = $this->server->validateAuthenticatedRequest($request);
            $encoded = substr($request->getHeaderLine('Authorization'), 7);
            if ($encoded === '') { throw new AuthenticationException('Missing bearer token.'); }
            /** @var non-empty-string $issuer */
            $issuer = rtrim($this->config->baseUrl, '/');
            $token = (new Parser(new JoseEncoder()))->parse($encoded);
            if (!$token->isPermittedFor($this->config->url('/mcp')) || !$token->hasBeenIssuedBy($issuer)) {
                throw new AuthenticationException('Token issued for a different resource.');
            }
            $id = $validated->getAttribute('oauth_access_token_id');
            if (!is_string($id)) { throw new AuthenticationException('Invalid token identifier.'); }
            $row = $this->store->row('SELECT * FROM mcp_access_tokens WHERE id = :id', ['id' => hash('sha256', $id)]);
            if ($row === null) { throw new AuthenticationException('Token is no longer valid.'); }
            /** @var list<string> $scopes */
            $scopes = json_decode((string) $row['scopes'], true, 32, JSON_THROW_ON_ERROR);
            return new Principal((string) $row['client_id'], $row['user_id'] === null ? null : (string) $row['user_id'], $scopes);
        } catch (OAuthServerException | \InvalidArgumentException $e) {
            throw new AuthenticationException('Invalid bearer token.', previous: $e);
        }
    }
}
