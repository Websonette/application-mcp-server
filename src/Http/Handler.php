<?php
declare(strict_types=1);

namespace Websonette\ApplicationMcpServer\Http;

use Mcp\Schema\Content\PromptMessage;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Enum\Role;
use Mcp\Schema\Tool;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server as McpServer;
use Mcp\Server\Transport\Http\Middleware\CorsMiddleware;
use Mcp\Server\Transport\StreamableHttpTransport;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Websonette\ApplicationMcpServer\Config;
use Websonette\ApplicationMcpServer\Content\Catalog;
use Websonette\ApplicationMcpServer\OAuth\Server;
use Websonette\ApplicationMcpServer\Persistence\Store;
use Websonette\ApplicationMcpServer\Security\AuthenticationException;
use Websonette\ApplicationMcpServer\Security\Authenticator;
use Websonette\ApplicationMcpServer\Security\Principal;
use Websonette\ApplicationMcpServer\Security\Scopes;
use Websonette\ApplicationMcpServer\Tool\Dispatcher;
use Websonette\ApplicationMcpServer\Tool\Registry;
use Websonette\ApplicationMcpServer\Tool\Risk;
use Websonette\ApplicationMcpServer\Tool\ToolException;

final readonly class Handler implements RequestHandlerInterface
{
    /** @param list<string> $scopes */
    public function __construct(
        private Config $config,
        private Store $store,
        private Authenticator $authenticator,
        private Registry $registry,
        private Dispatcher $dispatcher,
        private Catalog $catalog,
        private array $scopes,
        private ?Server $oauth = null,
    ) {
        Scopes::validate($scopes);
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $response = $this->dispatch($request)->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');
        $origin = $request->getHeaderLine('Origin');
        if (in_array($origin, $this->config->allowedOrigins, true)) {
            $response = $response->withHeader('Access-Control-Allow-Origin', $origin)->withHeader('Vary', 'Origin')
                ->withHeader('Access-Control-Expose-Headers', 'WWW-Authenticate, Mcp-Session-Id, X-Request-Id');
        }
        return $response;
    }

    private function dispatch(ServerRequestInterface $request): ResponseInterface
    {
        $factory = new Psr17Factory();
        $expected = $factory->createUri($this->config->baseUrl);
        // The adapter must restore a trusted external URI after validating its reverse proxy.
        if (strtolower($request->getHeaderLine('Host')) !== strtolower($expected->getAuthority())
            || $request->getUri()->getScheme() !== $expected->getScheme()
            || strtolower($request->getUri()->getAuthority()) !== strtolower($expected->getAuthority())) {
            return $this->json(403, ['error' => 'invalid_host']);
        }
        $origin = $request->getHeaderLine('Origin');
        if ($origin !== '' && $origin !== rtrim($this->config->baseUrl, '/') && !in_array($origin, $this->config->allowedOrigins, true)) {
            return $this->json(403, ['error' => 'invalid_origin']);
        }
        $path = $request->getUri()->getPath();
        if ($request->getMethod() === 'OPTIONS') {
            return (new Response(204))->withHeader('Access-Control-Allow-Methods', 'GET, POST, DELETE')
                ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept, MCP-Protocol-Version, Mcp-Session-Id, Mcp-Method, Mcp-Name');
        }
        if ($request->getMethod() === 'GET' && in_array($path, ['/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp'], true)) {
            return $this->json(200, ['resource' => $this->config->url('/mcp'), 'authorization_servers' => [rtrim($this->config->baseUrl, '/')],
                'scopes_supported' => $this->scopes, 'bearer_methods_supported' => ['header'], 'resource_name' => $this->config->name]);
        }
        if ($request->getMethod() === 'GET' && $path === '/.well-known/oauth-authorization-server') {
            return $this->json(200, ['issuer' => rtrim($this->config->baseUrl, '/'), 'authorization_endpoint' => $this->config->url('/oauth/authorize'),
                'token_endpoint' => $this->config->url('/oauth/token'), 'response_types_supported' => ['code'],
                'grant_types_supported' => ['authorization_code', 'refresh_token'], 'code_challenge_methods_supported' => ['S256'],
                'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
                'scopes_supported' => $this->scopes, 'authorization_response_iss_parameter_supported' => true]);
        }
        if ($path !== '/mcp' && !in_array($path, ['/oauth/authorize', '/oauth/token'], true)) {
            return $this->json(404, ['error' => 'not_found']);
        }
        $stream = $request->getBody();
        if ($stream->isSeekable()) { $stream->rewind(); }
        $body = '';
        while (!$stream->eof() && strlen($body) <= $this->config->maxBodyBytes) {
            $chunk = $stream->read(min(8192, $this->config->maxBodyBytes + 1 - strlen($body)));
            if ($chunk === '') { break; }
            $body .= $chunk;
        }
        if (strlen($body) > $this->config->maxBodyBytes) { return $this->json(413, ['error' => 'request_too_large']); }
        $request = $request->withBody($factory->createStream($body));
        if ($path !== '/mcp') {
            if ($this->oauth === null) { return $this->json(503, ['error' => 'oauth_not_configured']); }
            if ($request->getMethod() === 'POST' && $request->getParsedBody() === null
                && strtolower(explode(';', $request->getHeaderLine('Content-Type'))[0]) === 'application/x-www-form-urlencoded') {
                parse_str($body, $form);
                $request = $request->withParsedBody($form);
            }
            if ($path === '/oauth/authorize') { return $this->oauth->authorize($request); }
            if (strtolower(explode(';', $request->getHeaderLine('Content-Type'))[0]) !== 'application/x-www-form-urlencoded') {
                return $this->json(415, ['error' => 'unsupported_media_type']);
            }
            if ($request->getParsedBody() === null) {
                parse_str($body, $form);
                $request = $request->withParsedBody($form);
            }
            return $this->oauth->token($request);
        }
        try {
            $principal = $this->authenticator->authenticate($request);
            $this->store->assertClientActive($principal->clientId);
        } catch (AuthenticationException|ToolException) {
            return $this->challenge(401);
        }
        $requestId = bin2hex(random_bytes(16));
        if (!$this->store->rateLimit($principal, $this->config->requestsPerMinute)) {
            $this->store->audit($principal, 'request', 'rate_limited', $requestId);
            return $this->json(429, ['error' => 'rate_limited'])->withHeader('Retry-After', '60')->withHeader('X-Request-Id', $requestId);
        }
        $data = json_decode($body, true, 64);
        $needed = $this->requiredScopes($data);
        if (!$principal->permits($needed)) {
            $this->store->audit($principal, 'request', 'insufficient_scope', $requestId);
            return $this->challenge(403, $needed)->withHeader('X-Request-Id', $requestId);
        }
        $builder = McpServer::builder()->setServerInfo($this->config->name, $this->config->version)
            ->setSession(new SessionStore($this->store, $principal));
        foreach ($this->registry->all() as $definition) {
            if (!$principal->permits($definition->scopes)) { continue; }
            $schema = $definition->schema();
            /** @var array{type: 'object', properties: array<string, mixed>|\stdClass, required: list<string>} $schema */
            $tool = new Tool(name: $definition->name, title: null, inputSchema: $schema, description: $definition->description,
                annotations: new ToolAnnotations(readOnlyHint: $definition->risk === Risk::Read, destructiveHint: $definition->risk === Risk::Sensitive,
                    idempotentHint: true, openWorldHint: false), outputSchema: $definition->outputSchema);
            $builder->add($tool, new ToolHandler($this->dispatcher, $definition->name, $principal, $requestId));
        }
        foreach ($this->catalog->resources as $uri => $definition) {
            if (!$principal->permits($definition['scopes'])) { continue; }
            $builder->addResource(function () use ($definition, $principal, $requestId): string {
                $this->store->assertClientActive($principal->clientId);
                return $this->loadContent($definition['load'], $principal, 'resource_read', $requestId);
            }, $uri, $definition['name'], description: $definition['description'], mimeType: 'text/markdown');
        }
        foreach ($this->catalog->prompts as $name => $definition) {
            if (!$principal->permits($definition['scopes'])) { continue; }
            $builder->addPrompt(function () use ($definition, $principal, $requestId): array {
                $this->store->assertClientActive($principal->clientId);
                $text = $this->loadContent($definition['load'], $principal, 'prompt_get', $requestId);
                return [new PromptMessage(Role::User, new TextContent($text))];
            }, $name, description: $definition['description']);
        }
        $cors = new CorsMiddleware($this->config->allowedOrigins,
            allowedHeaders: ['Accept', 'Authorization', 'Content-Type', 'Last-Event-ID', 'Mcp-Protocol-Version', 'Mcp-Session-Id', 'Mcp-Method', 'Mcp-Name']);
        // Exact origin/host checks above replace the SDK's loopback-only host defaults.
        $transport = new StreamableHttpTransport($request, $factory, $factory, middleware: [$cors], maxBodyBytes: $this->config->maxBodyBytes);
        $response = $builder->build()->run($transport);
        return $response->withHeader('X-Request-Id', $requestId);
    }

    /** @param \Closure(Principal): string $load */
    private function loadContent(\Closure $load, Principal $principal, string $operation, string $requestId): string
    {
        try {
            $text = $load($principal);
            $this->store->audit($principal, $operation, 'success', $requestId);
            return $text;
        } catch (\Throwable) {
            $this->store->audit($principal, $operation, 'internal_error', $requestId);
            throw new \RuntimeException('Content is unavailable.');
        }
    }

    /** @return list<string> */
    private function requiredScopes(mixed $data): array
    {
        if (!is_array($data) || !is_array($data['params'] ?? null)) { return []; }
        $params = $data['params'];
        try {
            if (($data['method'] ?? null) === 'tools/call' && is_string($params['name'] ?? null)) {
                return $this->registry->get($params['name'])->scopes;
            }
        } catch (ToolException) { return []; }
        if (($data['method'] ?? null) === 'resources/read' && is_string($params['uri'] ?? null)) {
            return $this->catalog->resources[$params['uri']]['scopes'] ?? [];
        }
        if (($data['method'] ?? null) === 'prompts/get' && is_string($params['name'] ?? null)) {
            return $this->catalog->prompts[$params['name']]['scopes'] ?? [];
        }
        return [];
    }

    /** @param list<string> $scopes */
    private function challenge(int $status, array $scopes = []): ResponseInterface
    {
        $challenge = 'Bearer resource_metadata="' . $this->config->url('/.well-known/oauth-protected-resource/mcp') . '"';
        if ($status === 401) { $challenge .= ', error="invalid_token"'; }
        if ($scopes !== []) { $challenge .= ', error="insufficient_scope", scope="' . implode(' ', $scopes) . '"'; }
        return $this->json($status, ['error' => $status === 401 ? 'unauthorized' : 'insufficient_scope'])->withHeader('WWW-Authenticate', $challenge);
    }

    /** @param array<string, mixed> $data */
    private function json(int $status, array $data): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
    }
}
