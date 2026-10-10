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
            $authorization = $this->authorization->validateAuthorizationRequest($request);
            $resolution = $this->consent->resolve($request, $authorization);
            if ($resolution instanceof ResponseInterface) { return $resolution->withHeader('Cache-Control', 'no-store'); }
            $authorization->setUser($resolution);
            $authorization->setAuthorizationApproved(true);
            $response = $this->authorization->completeAuthorizationRequest($authorization, new Response());
            $separator = str_contains($response->getHeaderLine('Location'), '?') ? '&' : '?';
            return $response->withHeader('Location', $response->getHeaderLine('Location') . $separator . 'iss=' . rawurlencode(rtrim($this->config->baseUrl, '/')))
                ->withHeader('Cache-Control', 'no-store');
        } catch (OAuthServerException $e) {
            return $e->generateHttpResponse(new Response())->withHeader('Cache-Control', 'no-store');
        }
    }

    private function checkResource(mixed $parameters): void
    {
        if (is_array($parameters) && isset($parameters['resource']) && $parameters['resource'] !== $this->config->url('/mcp')) {
            throw OAuthServerException::invalidRequest('resource', 'Token target must be this MCP resource.');
        }
    }
}
