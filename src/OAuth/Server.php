<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\OAuth;

use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\ResourceServer;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Websonette\ApplicationMcpServer\Config;
use Websonette\ApplicationMcpServer\Persistence\Store;

final readonly class Server
{
    public AuthorizationServer $authorization;
    public ResourceServer $resource;

    public function __construct(
        private Config $config,
        private Store $store,
        private Repositories $repositories,
        string $privateKeyPath,
        string $publicKeyPath,
        #[\SensitiveParameter] string $encryptionKey,
        private ConsentAdapter $consent,
        public ?ClientRegistration $clients = null,
    ) {
        if (strlen($encryptionKey) < 32) { throw new \InvalidArgumentException('OAuth encryption key must have at least 32 bytes.'); }
        $this->authorization = new AuthorizationServer($repositories, $repositories, $repositories, new CryptKey($privateKeyPath), $encryptionKey);
        $code = new AuthCodeGrant($repositories, $repositories, new \DateInterval('PT5M'));
        $code->setRefreshTokenTTL(new \DateInterval('P30D'));
        $this->authorization->enableGrantType($code, new \DateInterval('PT15M'));
        $refresh = new RefreshTokenGrant($repositories);
        $refresh->setRefreshTokenTTL(new \DateInterval('P30D'));
        $this->authorization->enableGrantType($refresh, new \DateInterval('PT15M'));
        $this->resource = new ResourceServer($repositories, new CryptKey($publicKeyPath));
    }

    public function getResourceServer(): ResourceServer { return $this->resource; }

    public function token(ServerRequestInterface $request): ResponseInterface
    {
        if ($request->getMethod() !== 'POST') { return new Response(405, ['Allow' => 'POST']); }
        return $this->store->transaction(function () use ($request): ResponseInterface {
            try {
                $this->checkResource($request->getParsedBody());
                if ($this->clients !== null) { $this->checkClientAuthentication($request); }
                return $this->authorization->respondToAccessTokenRequest($request, new Response());
            } catch (OAuthServerException $e) {
                // Commit atomic claims and replay revocations even when OAuth rejects redemption.
                return $e->generateHttpResponse(new Response());
            }
        })->withHeader('Cache-Control', 'no-store');
    }

    public function authorize(ServerRequestInterface $request): ResponseInterface
    {
        if (!in_array($request->getMethod(), ['GET', 'POST'], true)) { return new Response(405, ['Allow' => 'GET, POST']); }
        try {
            $this->checkResource($request->getQueryParams());
            if (($request->getQueryParams()['code_challenge_method'] ?? null) !== 'S256') {
                throw OAuthServerException::invalidRequest('code_challenge_method', 'PKCE S256 is required.');
            }
            $challenge = $request->getQueryParams()['code_challenge'] ?? null;
            if (!is_string($challenge) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $challenge)) {
                throw OAuthServerException::invalidRequest('code_challenge', 'A valid S256 challenge is required for every client.');
            }
            $clientId = $request->getQueryParams()['client_id'] ?? null;
            if (is_string($clientId) && $this->clients !== null) {
                try { $this->clients->resolveCimd($clientId, $request); }
                catch (\InvalidArgumentException|\RuntimeException|\JsonException) { throw OAuthServerException::invalidClient($request); }
            }
            if ($this->clients !== null && is_string($clientId)) {
                $redirect = $request->getQueryParams()['redirect_uri'] ?? null;
                $this->repositories->redirectContext($clientId, is_string($redirect) ? $redirect : null);
            }
            $authorization = $this->authorization->validateAuthorizationRequest($request);
            $resolution = $this->consent->resolve($request, $authorization);
            if ($resolution instanceof ResponseInterface) { return $resolution->withHeader('Cache-Control', 'no-store'); }
            $authorization->setUser($resolution);
            $authorization->setAuthorizationApproved(true);
            $response = $this->authorization->completeAuthorizationRequest($authorization, new Response());
            return $this->withIssuer($response)->withHeader('Cache-Control', 'no-store');
        } catch (OAuthServerException $e) {
            return $this->withIssuer($e->generateHttpResponse(new Response()))->withHeader('Cache-Control', 'no-store');
        } finally {
            $this->repositories->redirectContext(null, null);
        }
    }

    private function checkClientAuthentication(ServerRequestInterface $request): void
    {
        $body = $request->getParsedBody();
        $id = is_array($body) && is_string($body['client_id'] ?? null) ? $body['client_id'] : null;
        $header = $request->getHeaderLine('Authorization');
        $basic = preg_match('/^Basic /i', $header) === 1;
        if ($basic) {
            $decoded = base64_decode(substr($header, 6), true);
            $basicId = is_string($decoded) ? urldecode(explode(':', $decoded, 2)[0]) : '';
            if ($id !== null && $id !== $basicId) { throw OAuthServerException::invalidClient($request); }
            $id = $basicId;
        }
        if ($id === null) { return; }
        $row = $this->store->row('SELECT auth_method FROM mcp_client_metadata WHERE client_id = :id', ['id' => $id]);
        $method = $row['auth_method'] ?? null;
        $secret = is_array($body) && isset($body['client_secret']);
        if (($method === 'none' && ($basic || $secret)) || ($method === 'client_secret_basic' && (!$basic || $secret))
            || ($method === 'client_secret_post' && ($basic || !$secret))) { throw OAuthServerException::invalidClient($request); }
    }

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        $metadata = ['issuer' => rtrim($this->config->baseUrl, '/'), 'authorization_endpoint' => $this->config->url('/oauth/authorize'),
            'token_endpoint' => $this->config->url('/oauth/token'), 'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'], 'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
            'scopes_supported' => $this->repositories->scopes instanceof \Websonette\ApplicationMcpServer\Security\ScopeCatalog
                ? $this->repositories->scopes->identifiers() : $this->repositories->scopes,
            'authorization_response_iss_parameter_supported' => true];
        if ($this->clients?->cimd) { $metadata['client_id_metadata_document_supported'] = true; }
        if ($this->clients?->dcr) { $metadata['registration_endpoint'] = $this->config->url('/oauth/register'); }
        return $metadata;
    }
    private function withIssuer(ResponseInterface $response): ResponseInterface
    {
        $location = $response->getHeaderLine('Location');
        return $location === '' ? $response : $response->withHeader('Location', $location
            . (str_contains($location, '?') ? '&' : '?') . 'iss=' . rawurlencode(rtrim($this->config->baseUrl, '/')));
    }

    private function checkResource(mixed $parameters): void
    {
        if (is_array($parameters) && isset($parameters['resource']) && $parameters['resource'] !== $this->config->url('/mcp')) {
            throw OAuthServerException::invalidRequest('resource', 'Token target must be this MCP resource.');
        }
    }
}
