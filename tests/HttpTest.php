<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\Tests;

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Websonette\ApplicationMcpServer\Config;
use Websonette\ApplicationMcpServer\Content\Catalog;
use Websonette\ApplicationMcpServer\Http\Handler;
use Websonette\ApplicationMcpServer\Security\Principal;
use Websonette\ApplicationMcpServer\Security\Tokens;
use Websonette\ApplicationMcpServer\Tests\Support\DatabaseTestCase;
use Websonette\ApplicationMcpServer\Tool\Definition;
use Websonette\ApplicationMcpServer\Tool\Risk;

final class HttpTest extends DatabaseTestCase
{
    private Handler $http;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tools->add(new Definition('read_item', 'Read the draft.', ['type' => 'object'], ['read'], Risk::Read, static fn (array $args, Principal $p): array => ['title' => 'Draft']));
        $this->tools->add(new Definition('write_item', 'Write.', ['type' => 'object'], ['write'], Risk::Write, static fn (array $args, Principal $p): array => ['ok' => true]));
        $this->tools->add(new Definition('failing_item', 'Safe errors.', ['type' => 'object'], ['read'], Risk::Read, static function (array $args, Principal $p): array { throw new \RuntimeException('password=TOP_SECRET'); }));
        $catalog = new Catalog();
        $catalog->resource('project://profile', 'profile', 'Project profile', ['read'], static fn (Principal $p): string => 'A project profile.');
        $catalog->prompt('draft_copy', 'Draft marketing copy', ['read'], static fn (Principal $p): string => 'Follow the project tone of voice.');
        $tokens = new Tokens($this->store, $this->config);
        $this->token = $tokens->issue('test-client', ['read'], 'user-1');
        $this->http = new Handler($this->config, $this->store, $tokens, $this->tools, $this->dispatcher, $catalog, ['read', 'write', 'publish']);
    }

    /** @param array<string, mixed> $params */
    private function modern(string $method, array $params = []): ResponseInterface
    {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            'io.modelcontextprotocol/clientInfo' => ['name' => 'integration-test', 'version' => '1.0'],
        ];
        $headers = ['Authorization' => 'Bearer ' . $this->token, 'Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream',
            'MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => $method];
        $name = $params['name'] ?? $params['uri'] ?? null;
        if (is_string($name)) { $headers['Mcp-Name'] = $name; }
        return $this->http->handle(new ServerRequest('POST', $this->config->url('/mcp'), $headers,
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], JSON_THROW_ON_ERROR)));
    }

    /** @return array<string, mixed> */
    private function data(ResponseInterface $response): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $response->getBody(), true, 64, JSON_THROW_ON_ERROR);
    }

    public function testDiscoveryAndBearerChallenge(): void
    {
        $response = $this->http->handle(new ServerRequest('POST', $this->config->url('/mcp')));
        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('resource_metadata=', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $metadata = $this->data($this->http->handle(new ServerRequest('GET', $this->config->url('/.well-known/oauth-protected-resource/mcp'))));
        self::assertSame($this->config->url('/mcp'), $metadata['resource']);
        $auth = $this->data($this->http->handle(new ServerRequest('GET', $this->config->url('/.well-known/oauth-authorization-server'))));
        self::assertSame(['S256'], $auth['code_challenge_methods_supported']);
        self::assertArrayNotHasKey('registration_endpoint', $auth);
    }

    public function testModernSdkListsOnlyPermittedToolsAndReturnsStructuredResult(): void
    {
        $response = $this->modern('tools/list');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $data = $this->data($response);
        self::assertSame(['read_item', 'failing_item'], array_column($data['result']['tools'], 'name'));
        $result = $this->data($this->modern('tools/call', ['name' => 'read_item', 'arguments' => new \stdClass()]));
        self::assertSame(['title' => 'Draft'], $result['result']['structuredContent']);
    }

    public function testRuntimeScopeChallengeDoesNotRunTool(): void
    {
        $response = $this->modern('tools/call', ['name' => 'write_item', 'arguments' => ['idempotency_key' => 'x']]);
        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('scope="write"', $response->getHeaderLine('WWW-Authenticate'));
        self::assertNotEmpty($response->getHeaderLine('X-Request-Id'));
    }

    public function testResourcesAndPromptsUseSdkAndScopes(): void
    {
        $resources = $this->data($this->modern('resources/list'));
        self::assertSame('project://profile', $resources['result']['resources'][0]['uri']);
        $read = $this->data($this->modern('resources/read', ['uri' => 'project://profile']));
        self::assertSame('A project profile.', $read['result']['contents'][0]['text']);
        $response = $this->modern('prompts/get', ['name' => 'draft_copy']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $prompt = $this->data($response);
        self::assertSame('Follow the project tone of voice.', $prompt['result']['messages'][0]['content']['text']);
    }

    public function testLegacyHandshakePersistsBetweenPhpRequests(): void
    {
        $headers = ['Authorization' => 'Bearer ' . $this->token, 'Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream'];
        $init = $this->http->handle(new ServerRequest('POST', $this->config->url('/mcp'), $headers,
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2025-11-25', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1'],
            ]], JSON_THROW_ON_ERROR)));
        self::assertSame(200, $init->getStatusCode(), (string) $init->getBody());
        $session = $init->getHeaderLine('Mcp-Session-Id');
        self::assertNotEmpty($session);
        $headers['Mcp-Session-Id'] = $session;
        $headers['MCP-Protocol-Version'] = '2025-11-25';
        $this->http->handle(new ServerRequest('POST', $this->config->url('/mcp'), $headers, '{"jsonrpc":"2.0","method":"notifications/initialized"}'));
        $list = $this->http->handle(new ServerRequest('POST', $this->config->url('/mcp'), $headers, '{"jsonrpc":"2.0","id":2,"method":"tools/list"}'));
        self::assertSame(200, $list->getStatusCode(), (string) $list->getBody());
        self::assertCount(2, $this->data($list)['result']['tools']);
        $other = (new Tokens($this->store, $this->config))->issue('test-client', ['read'], 'another-user');
        $headers['Authorization'] = 'Bearer ' . $other;
        $stolen = $this->http->handle(new ServerRequest('POST', $this->config->url('/mcp'), $headers, '{"jsonrpc":"2.0","id":3,"method":"tools/list"}'));
        self::assertSame(404, $stolen->getStatusCode());
    }

    public function testHostOriginSizeAndRevocationAreEnforced(): void
    {
        self::assertSame(403, $this->http->handle(new ServerRequest('POST', $this->config->url('/mcp'), ['Host' => 'evil.example']))->getStatusCode());
        self::assertSame(403, $this->http->handle(new ServerRequest('POST', $this->config->url('/mcp'), ['Origin' => 'https://evil.example']))->getStatusCode());
        self::assertSame(413, $this->http->handle(new ServerRequest('POST', $this->config->url('/mcp'), [], str_repeat('x', 1048577)))->getStatusCode());
        $this->admin->disconnectClient('test-client');
        self::assertSame(401, $this->modern('tools/list')->getStatusCode());
    }

    public function testErrorsDoNotExposeExceptionDetails(): void
    {
        $response = $this->modern('tools/call', ['name' => 'failing_item', 'arguments' => new \stdClass()]);
        self::assertStringNotContainsString('TOP_SECRET', (string) $response->getBody());
        $result = $this->data($response);
        self::assertTrue($result['result']['isError']);
        self::assertStringContainsString('internal_error', $result['result']['content'][0]['text']);
    }

    public function testRateLimitReturns429AndResetsNextMinute(): void
    {
        $config = new Config($this->config->baseUrl, development: true, requestsPerMinute: 1);
        $tokens = new Tokens($this->store, $config);
        $http = new Handler($config, $this->store, $tokens, $this->tools, $this->dispatcher, new Catalog(), ['read']);
        $request = new ServerRequest('POST', $config->url('/mcp'), ['Authorization' => 'Bearer ' . $this->token], '{}');
        $http->handle($request);
        self::assertSame(429, $http->handle($request)->getStatusCode());
        $this->clock->offset = 61;
        self::assertNotSame(429, $http->handle($request)->getStatusCode());
    }
}
