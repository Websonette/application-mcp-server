<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\Tests;

use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Websonette\ApplicationMcpServer\Config;
use Websonette\ApplicationMcpServer\Console\KeyGenerator;
use Websonette\ApplicationMcpServer\OAuth\BearerAuthenticator;
use Websonette\ApplicationMcpServer\OAuth\ConsentAdapter;
use Websonette\ApplicationMcpServer\OAuth\Repositories;
use Websonette\ApplicationMcpServer\OAuth\Server;
use Websonette\ApplicationMcpServer\OAuth\User;
use Websonette\ApplicationMcpServer\Security\AuthenticationException;
use Websonette\ApplicationMcpServer\Security\Tokens;
use Websonette\ApplicationMcpServer\Tests\Support\DatabaseTestCase;

final class OAuthTest extends DatabaseTestCase
{
    private static string $keyDirectory;
    private Server $oauth;
    private BearerAuthenticator $auth;
    private string $verifier;
    private TestConsent $consent;

    public static function setUpBeforeClass(): void
    {
        self::$keyDirectory = sys_get_temp_dir() . '/websonette-oauth-test-' . bin2hex(random_bytes(8));
        (new KeyGenerator())->generate(self::$keyDirectory);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (['private.pem', 'public.pem', 'encryption.key'] as $file) { unlink(self::$keyDirectory . '/' . $file); }
        rmdir(self::$keyDirectory);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = new Config('https://application.example');
        $repositories = new Repositories($this->store, $this->config, ['read', 'write', 'publish']);
        $this->consent = new TestConsent();
        $this->oauth = new Server($this->config, $this->store, $repositories, self::$keyDirectory . '/private.pem', self::$keyDirectory . '/public.pem',
            str_repeat('test-encryption-key-', 3), $this->consent);
        $this->auth = new BearerAuthenticator($this->oauth->resource, $this->store, $this->config, new Tokens($this->store, $this->config));
        $this->verifier = str_repeat('a', 64);
    }

    /** @param array<string, mixed> $changes */
    private function authorization(array $changes = []): ResponseInterface
    {
        $params = array_replace([
            'client_id' => 'test-client', 'response_type' => 'code', 'redirect_uri' => 'http://127.0.0.1:9999/callback',
            'scope' => 'read', 'state' => 'random-client-state', 'resource' => $this->config->url('/mcp'),
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $this->verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ], $changes);
        return $this->oauth->authorize((new ServerRequest('GET', $this->config->url('/oauth/authorize') . '?' . http_build_query($params)))->withQueryParams($params));
    }

    private function code(): string
    {
        $response = $this->authorization();
        self::assertSame(302, $response->getStatusCode(), (string) $response->getBody());
        parse_str((string) parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $query);
        self::assertSame('random-client-state', $query['state']);
        self::assertSame('https://application.example', $query['iss']);
        self::assertIsString($query['code']);
        return $query['code'];
    }

    /** @param array<string, string> $form */
    private function token(array $form): ResponseInterface
    {
        return $this->oauth->token((new ServerRequest('POST', $this->config->url('/oauth/token')))->withParsedBody($form));
    }

    /** @return array{access_token: string, refresh_token: string, expires_in: int, token_type: string} */
    private function redeem(string $code): array
    {
        $response = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => 'test-client',
            'redirect_uri' => 'http://127.0.0.1:9999/callback', 'code_verifier' => $this->verifier, 'resource' => $this->config->url('/mcp')]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        /** @var array{access_token: string, refresh_token: string, expires_in: int, token_type: string} */
        return json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
    }

    public function testPkceFlowPersistsNoBearerSecretsAndBindsResource(): void
    {
        $result = $this->redeem($this->code());
        self::assertSame('Bearer', $result['token_type']);
        self::assertLessThanOrEqual(900, $result['expires_in']);
        $principal = $this->auth->authenticate(new ServerRequest('POST', $this->config->url('/mcp'), ['Authorization' => 'Bearer ' . $result['access_token']]));
        self::assertSame('test-client', $principal->clientId);
        self::assertSame('human-user', $principal->userId);
        self::assertSame(['read'], $principal->scopes);
        $stored = $this->admin->tokens('test-client');
        self::assertStringNotContainsString($result['access_token'], json_encode($stored, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($result['refresh_token'], json_encode($stored, JSON_THROW_ON_ERROR));
        $otherConfig = new Config('https://other.example');
        $other = new BearerAuthenticator($this->oauth->resource, $this->store, $otherConfig, new Tokens($this->store, $otherConfig));
        $this->expectException(AuthenticationException::class);
        $other->authenticate(new ServerRequest('POST', $otherConfig->url('/mcp'), ['Authorization' => 'Bearer ' . $result['access_token']]));
    }

    public function testWrongVerifierDoesNotConsumeCodeAndReplayFails(): void
    {
        $code = $this->code();
        $wrong = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => 'test-client',
            'redirect_uri' => 'http://127.0.0.1:9999/callback', 'code_verifier' => str_repeat('b', 64)]);
        self::assertSame(400, $wrong->getStatusCode());
        $this->redeem($code);
        $again = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'client_id' => 'test-client',
            'redirect_uri' => 'http://127.0.0.1:9999/callback', 'code_verifier' => $this->verifier]);
        self::assertSame(400, $again->getStatusCode());
        self::assertCount(1, $this->admin->tokens('test-client'));
    }

    public function testRefreshRotatesAndReplayRevokesClientGrants(): void
    {
        $initial = $this->redeem($this->code());
        $form = ['grant_type' => 'refresh_token', 'client_id' => 'test-client', 'refresh_token' => $initial['refresh_token'], 'resource' => $this->config->url('/mcp')];
        $response = $this->token($form);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        /** @var array{access_token: string, refresh_token: string} $rotated */
        $rotated = json_decode((string) $response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        self::assertNotSame($initial['refresh_token'], $rotated['refresh_token']);
        $request = new ServerRequest('POST', $this->config->url('/mcp'), ['Authorization' => 'Bearer ' . $rotated['access_token']]);
        self::assertSame('human-user', $this->auth->authenticate($request)->userId);
        self::assertSame(400, $this->token($form)->getStatusCode());
        $this->expectException(AuthenticationException::class);
        $this->auth->authenticate($request);
    }

    public function testConsentIsNotAutomaticAndScopesCanBeReduced(): void
    {
        $this->consent->approved = false;
        self::assertSame(403, $this->authorization()->getStatusCode());
        self::assertNull($this->store->row('SELECT * FROM mcp_authorization_codes'));
        $this->consent->approved = true;
        $this->consent->reduce = true;
        $response = $this->authorization(['scope' => 'read write']);
        parse_str((string) parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $query);
        self::assertIsString($query['code']);
        $token = $this->redeem($query['code']);
        $principal = $this->auth->authenticate(new ServerRequest('POST', $this->config->url('/mcp'), ['Authorization' => 'Bearer ' . $token['access_token']]));
        self::assertSame(['read'], $principal->scopes);
    }

    public function testRejectsPlainPkceUnknownScopeRedirectAndResource(): void
    {
        self::assertSame(400, $this->authorization(['code_challenge_method' => 'plain'])->getStatusCode());
        $unknown = $this->authorization(['scope' => 'unknown']);
        self::assertSame(302, $unknown->getStatusCode());
        self::assertStringStartsWith('http://127.0.0.1:9999/callback?', $unknown->getHeaderLine('Location'));
        self::assertStringContainsString('error=invalid_scope', $unknown->getHeaderLine('Location'));
        $redirect = $this->authorization(['redirect_uri' => 'https://evil.example/callback']);
        self::assertSame(401, $redirect->getStatusCode());
        self::assertSame('', $redirect->getHeaderLine('Location'));
        self::assertSame(400, $this->authorization(['resource' => 'https://other.example/mcp'])->getStatusCode());
        self::assertNull($this->store->row('SELECT * FROM mcp_authorization_codes'));
    }

    public function testConfidentialClientsAlsoRequirePkceAndCorrectSecret(): void
    {
        $secret = str_repeat('s', 64);
        $this->admin->registerClient('confidential-client', 'Confidential test', ['http://127.0.0.1:9999/callback'], ['read'], $secret);
        $missing = $this->authorization(['client_id' => 'confidential-client', 'code_challenge' => null]);
        self::assertSame(400, $missing->getStatusCode());
        $response = $this->authorization(['client_id' => 'confidential-client']);
        self::assertSame(302, $response->getStatusCode());
        parse_str((string) parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $callback);
        self::assertIsString($callback['code']);
        $form = ['grant_type' => 'authorization_code', 'client_id' => 'confidential-client', 'client_secret' => 'wrong',
            'code' => $callback['code'], 'redirect_uri' => 'http://127.0.0.1:9999/callback', 'code_verifier' => $this->verifier];
        self::assertSame(401, $this->token($form)->getStatusCode());
        $form['client_secret'] = $secret;
        self::assertSame(200, $this->token($form)->getStatusCode());
        self::assertArrayNotHasKey('secret_hash', $this->admin->clients()[0]);
    }

    public function testExpiredAndTamperedTokensAreRejected(): void
    {
        $tokens = $this->redeem($this->code());
        $request = new ServerRequest('POST', $this->config->url('/mcp'), ['Authorization' => 'Bearer ' . $tokens['access_token']]);
        $parts = explode('.', $tokens['access_token']);
        $parts[1] = rtrim(strtr(base64_encode('{"sub":"attacker","aud":["https://application.example/mcp"],"exp":9999999999}'), '+/', '-_'), '=');
        try {
            $this->auth->authenticate($request->withHeader('Authorization', 'Bearer ' . implode('.', $parts)));
            self::fail('Tampered token accepted.');
        } catch (AuthenticationException $e) { self::assertSame('Invalid bearer token.', $e->getMessage()); }
        $this->clock->offset = 901;
        $this->expectException(AuthenticationException::class);
        $this->auth->authenticate($request);
    }

    public function testDisconnectedClientCannotUseAccessOrRefresh(): void
    {
        $token = $this->redeem($this->code());
        $this->admin->disconnectClient('test-client');
        self::assertSame(401, $this->token(['grant_type' => 'refresh_token', 'client_id' => 'test-client', 'refresh_token' => $token['refresh_token']])->getStatusCode());
        $this->expectException(AuthenticationException::class);
        $this->auth->authenticate(new ServerRequest('POST', $this->config->url('/mcp'), ['Authorization' => 'Bearer ' . $token['access_token']]));
    }

    public function testTokenRevocationAlsoRevokesRefresh(): void
    {
        $token = $this->redeem($this->code());
        $stored = $this->admin->tokens('test-client');
        $this->admin->revokeToken((string) $stored[0]['id']);
        self::assertSame(400, $this->token(['grant_type' => 'refresh_token', 'client_id' => 'test-client', 'refresh_token' => $token['refresh_token']])->getStatusCode());
        $this->expectException(AuthenticationException::class);
        $this->auth->authenticate(new ServerRequest('POST', $this->config->url('/mcp'), ['Authorization' => 'Bearer ' . $token['access_token']]));
    }
}

/** Test-only consent adapter. Never use this automatic approval in an application. */
final class TestConsent implements ConsentAdapter
{
    public bool $approved = true;
    public bool $reduce = false;
    public function resolve(ServerRequestInterface $request, AuthorizationRequestInterface $authorization): User|ResponseInterface
    {
        if (!$this->approved) { return new Response(403); }
        if ($this->reduce) {
            $authorization->setScopes(array_values(array_filter($authorization->getScopes(), static fn ($scope): bool => $scope->getIdentifier() === 'read')));
        }
        return new User('human-user');
    }
}
