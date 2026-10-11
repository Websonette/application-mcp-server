<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\Tests;

use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use Nyholm\Psr7\ServerRequest;
use Websonette\ApplicationMcpServer\OAuth\Client;
use Websonette\ApplicationMcpServer\OAuth\ClientRegistration;
use Websonette\ApplicationMcpServer\OAuth\ConsentState;
use Websonette\ApplicationMcpServer\OAuth\DefaultConsent;
use Websonette\ApplicationMcpServer\OAuth\HtmlConsentRenderer;
use Websonette\ApplicationMcpServer\OAuth\HttpsMetadataFetcher;
use Websonette\ApplicationMcpServer\OAuth\IdentityProvider;
use Websonette\ApplicationMcpServer\OAuth\MetadataFetcher;
use Websonette\ApplicationMcpServer\OAuth\Scope;
use Websonette\ApplicationMcpServer\OAuth\User;
use Websonette\ApplicationMcpServer\Security\ScopeCatalog;
use Websonette\ApplicationMcpServer\Security\ScopeDefinition;
use Websonette\ApplicationMcpServer\Security\ScopeProvider;
use Websonette\ApplicationMcpServer\Tests\Support\DatabaseTestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class OnboardingTest extends DatabaseTestCase
{
    private function clients(TestFetcher $fetcher, bool $dcr = true): ClientRegistration
    {
        return new ClientRegistration($this->store, new ScopeCatalog(['read', 'write']), $this->admin, $fetcher, true, $dcr);
    }
    /** @param array<string, mixed> $metadata */
    private function registration(array $metadata): ServerRequest
    {
        return new ServerRequest('POST', $this->config->url('/oauth/register'), ['Content-Type' => 'application/json'],
            json_encode($metadata, JSON_THROW_ON_ERROR), serverParams: ['REMOTE_ADDR' => '198.51.100.1']);
    }
    public function testDcrCreatesPkceClientWithoutIssuingAnyTokens(): void
    {
        $clients = $this->clients(new TestFetcher());
        $response = $clients->register($this->registration(['client_name' => 'ChatGPT', 'redirect_uris' => ['https://chatgpt.com/connector_platform_oauth_redirect'],
            'token_endpoint_auth_method' => 'none', 'scope' => 'read']));
        self::assertSame(201, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertArrayNotHasKey('client_secret', $body);
        self::assertNull($this->store->row('SELECT id FROM mcp_access_tokens'));
        self::assertSame(404, $this->clients(new TestFetcher(), false)->register($this->registration([]))->getStatusCode());
    }
    public function testDcrRejectsMalformedFlowsScopesAndRedirectsAndHasQuota(): void
    {
        $clients = $this->clients(new TestFetcher());
        $valid = ['redirect_uris' => ['https://client.example/callback'], 'token_endpoint_auth_method' => 'none'];
        foreach ([
            ['redirect_uris' => ['http://private.example/callback']],
            ['redirect_uris' => ['https://client.example/callback#fragment']],
            ['scope' => 'unknown'],
            ['grant_types' => [['authorization_code']]],
            ['grant_types' => ['client_credentials']],
            ['token_endpoint_auth_method' => 'private_key_jwt'],
        ] as $invalid) {
            self::assertSame(400, $clients->register($this->registration(array_replace($valid, $invalid)))->getStatusCode());
        }
        for ($i = 0; $i < 4; $i++) { self::assertSame(201, $clients->register($this->registration($valid))->getStatusCode()); }
        self::assertSame(429, $clients->register($this->registration($valid))->getStatusCode());
    }
    public function testConfidentialDcrSecretIsOnlyReturnedOnceAndStoredHashed(): void
    {
        $response = $this->clients(new TestFetcher())->register($this->registration(['redirect_uris' => ['https://client.example/callback']]));
        $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsString($body['client_id']);
        self::assertIsString($body['client_secret']);
        $row = $this->store->row('SELECT secret_hash FROM mcp_clients WHERE id = :id', ['id' => $body['client_id']]);
        self::assertNotNull($row);
        self::assertNotSame($body['client_secret'], $row['secret_hash']);
        self::assertTrue(password_verify($body['client_secret'], (string) $row['secret_hash']));
    }
    public function testCimdCachesMetadataChoosesNoneFromPluralMethodsAndDoesNotResurrectRevokedClient(): void
    {
        $fetcher = new TestFetcher();
        $clients = $this->clients($fetcher);
        $id = 'https://chatgpt.com/oauth/client.json';
        $request = new ServerRequest('GET', $this->config->url('/oauth/authorize'));
        $clients->resolveCimd($id, $request);
        $clients->resolveCimd($id, $request);
        self::assertSame(1, $fetcher->calls);
        self::assertNotNull($this->store->row('SELECT id FROM mcp_clients WHERE id = :id', ['id' => $id]));
        $this->admin->disconnectClient($id);
        $this->expectException(\InvalidArgumentException::class);
        $clients->resolveCimd($id, $request);
    }
    public function testCimdRejectsMismatchedIdentityAndUnsafeRefreshRedirects(): void
    {
        $fetcher = new TestFetcher();
        $clients = $this->clients($fetcher);
        $id = 'https://chatgpt.com/oauth/client.json';
        $request = new ServerRequest('GET', $this->config->url('/oauth/authorize'));
        $clients->resolveCimd($id, $request);
        $this->clock->offset = 3601;
        $fetcher->redirect = 'http://evil.example/callback';
        try { $clients->resolveCimd($id, $request); self::fail('Unsafe metadata accepted.'); }
        catch (\InvalidArgumentException) { self::assertSame(2, $fetcher->calls); }
        $fetcher->id = 'https://other.example/client.json';
        $this->expectException(\InvalidArgumentException::class);
        $clients->resolveCimd($id, $request);
    }
    public function testPublicMetadataFetcherRejectsLocalAndNonHttpsUrls(): void
    {
        foreach (['127.0.0.1', '10.0.0.1', '169.254.169.254', '100.64.0.1', '::1', 'fc00::1', 'fe80::1', '::ffff:127.0.0.1'] as $ip) {
            self::assertFalse(HttpsMetadataFetcher::isPublicAddress($ip), $ip);
        }
        self::assertTrue(HttpsMetadataFetcher::isPublicAddress('1.1.1.1'));
        foreach (['http://client.example/client.json', 'https://127.0.0.1/client.json', 'https://client.example:444/client.json',
            'https://user@client.example/client.json', 'https://client.example/client.json#x', 'https://client.example/'] as $url) {
            try { (new HttpsMetadataFetcher())->fetch($url); self::fail('Unsafe URL accepted.'); }
            catch (\InvalidArgumentException $error) { self::assertNotSame('', $error->getMessage()); }
        }
    }
    public function testConsentRequiresSessionBoundSingleUseRequestAndCurrentIdentity(): void
    {
        $identity = new TestIdentity();
        $state = new MemoryConsentState();
        $consent = new DefaultConsent($identity, $state, new HtmlConsentRenderer(), new ScopeCatalog(['read', 'write']), $this->store, $this->config);
        $authorization = $this->authorizationRequest();
        $request = new ServerRequest('GET', $this->config->url('/oauth/authorize'));
        $page = $consent->resolve($request, $authorization);
        self::assertInstanceOf(ResponseInterface::class, $page);
        self::assertSame(1, preg_match('/name="csrf" value="([^"]+)"/', (string) $page->getBody(), $matches));
        self::assertArrayHasKey(1, $matches);
        $token = $matches[1] ?? '';
        self::assertNotSame('', $token);
        $post = $request->withMethod('POST')->withParsedBody(['action' => 'approve', 'csrf' => $token]);
        $other = $this->authorizationRequest();
        $other->setState('different');
        $invalid = $consent->resolve($post, $other);
        self::assertInstanceOf(ResponseInterface::class, $invalid);
        self::assertSame(403, $invalid->getStatusCode());
        $approved = $this->authorizationRequest();
        self::assertInstanceOf(User::class, $consent->resolve($post, $approved));
        self::assertCount(1, $approved->getScopes());
        $replay = $consent->resolve($post, $this->authorizationRequest());
        self::assertInstanceOf(ResponseInterface::class, $replay);
        self::assertSame(403, $replay->getStatusCode());
    }
    public function testScopeProviderIsSingleSourceAndUnknownCapabilitiesFail(): void
    {
        $catalog = new ScopeCatalog(new class implements ScopeProvider {
            public function getScopes(): iterable { yield new ScopeDefinition('read', 'Read records', 'Read only.'); }
        });
        self::assertSame(['read'], $catalog->identifiers());
        self::assertSame('Read records', $catalog->get('read')->label);
        $this->expectException(\InvalidArgumentException::class);
        $catalog->validate(['unknown']);
    }
    private function authorizationRequest(): AuthorizationRequest
    {
        $authorization = new AuthorizationRequest();
        $authorization->setClient(new Client('test-client', 'Test', ['https://client.example/callback'], false));
        $authorization->setRedirectUri('https://client.example/callback');
        $authorization->setState('state');
        $authorization->setScopes([new Scope('read'), new Scope('write')]);
        $authorization->setCodeChallenge(str_repeat('a', 43));
        $authorization->setCodeChallengeMethod('S256');
        return $authorization;
    }
}

final class TestFetcher implements MetadataFetcher
{
    public int $calls = 0;
    public string $id = 'https://chatgpt.com/oauth/client.json';
    public string $redirect = 'https://chatgpt.com/connector_platform_oauth_redirect';
    /** @return array<string, mixed> */
    public function fetch(string $url): array
    {
        $this->calls++;
        return ['client_id' => $this->id, 'client_name' => 'ChatGPT', 'redirect_uris' => [$this->redirect],
            'token_endpoint_auth_methods_supported' => ['none', 'private_key_jwt'], 'token_endpoint_auth_method' => 'private_key_jwt'];
    }
}

final class TestIdentity implements IdentityProvider
{
    public ?string $id = 'human';
    public function currentUser(ServerRequestInterface $request): ?User { return $this->id === null || $this->id === '' ? null : new User($this->id); }
    public function login(ServerRequestInterface $request, string $username, #[\SensitiveParameter] string $password): ?User
    {
        if ($username !== 'human' || $password !== 'password') { return null; }
        $this->id = 'human';
        return new User('human');
    }
    /** @return list<string> */
    public function allowedScopes(User $user): array { return ['read']; }
}

/** Test-only in-memory session substitute; production uses SessionConsentState. */
final class MemoryConsentState implements ConsentState
{
    /** @var array<string, array{string, ?string}> */
    private array $tokens = [];
    public function issue(string $binding, ?string $subject): string
    {
        $token = bin2hex(random_bytes(32));
        $this->tokens[$token] = [$binding, $subject];
        return $token;
    }
    public function consume(string $token, string $binding, ?string $subject): bool
    {
        if (($this->tokens[$token] ?? null) !== [$binding, $subject]) { return false; }
        unset($this->tokens[$token]);
        return true;
    }
}
