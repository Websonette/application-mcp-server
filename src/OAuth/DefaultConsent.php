<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\OAuth;

use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Websonette\ApplicationMcpServer\Config;
use Websonette\ApplicationMcpServer\Persistence\Store;
use Websonette\ApplicationMcpServer\Security\ScopeCatalog;

final readonly class DefaultConsent implements ConsentAdapter
{
    public function __construct(private IdentityProvider $identity, private ConsentState $state, private ConsentRenderer $renderer,
        private ScopeCatalog $scopes, private Store $store, private Config $config) {}

    public function resolve(ServerRequestInterface $request, AuthorizationRequestInterface $authorization): User|ResponseInterface
    {
        $user = $this->identity->currentUser($request);
        $requested = array_map(static fn ($scope): string => $scope->getIdentifier(), $authorization->getScopes());
        sort($requested);
        $binding = hash('sha256', json_encode([$authorization->getClient()->getIdentifier(), $authorization->getRedirectUri(),
            $requested, $authorization->getState(), $authorization->getCodeChallenge(), $authorization->getCodeChallengeMethod(),
            $request->getQueryParams()['resource'] ?? null], JSON_THROW_ON_ERROR));
        $allowed = $user === null ? [] : $this->identity->allowedScopes($user);
        $this->scopes->validate($allowed);
        $formBinding = $this->formBinding($binding, $requested, $allowed);
        $post = $request->getParsedBody();
        $action = is_array($post) && is_string($post['action'] ?? null) ? $post['action'] : '';
        if ($request->getMethod() === 'POST') {
            $token = is_array($post) && is_string($post['csrf'] ?? null) ? $post['csrf'] : '';
            if (!in_array($action, ['login', 'approve', 'deny'], true)
                || !$this->state->consume($token, $formBinding, $user?->getIdentifier())) {
                return $this->html(403, $this->renderer->message('Invalid or expired consent form.'));
            }
            if ($action === 'login') {
                $ip = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';
                if (!$this->store->hit('oauth_login', is_string($ip) ? $ip : 'unknown', 8, 900)) {
                    return $this->html(429, $this->renderer->message('Too many sign-in attempts.'));
                }
                $username = is_string($post['username'] ?? null) ? $post['username'] : '';
                $password = is_string($post['password'] ?? null) ? $post['password'] : '';
                $user = $this->identity->login($request, $username, $password);
                if ($user === null) { return $this->html(401, $this->renderer->login($this->state->issue($formBinding, null), true)); }
                $allowed = $this->identity->allowedScopes($user);
                $this->scopes->validate($allowed);
                $formBinding = $this->formBinding($binding, $requested, $allowed);
            } elseif ($action === 'deny') {
                $redirect = (string) $authorization->getRedirectUri();
                return new Response(302, ['Location' => $redirect . (str_contains($redirect, '?') ? '&' : '?')
                    . http_build_query(['error' => 'access_denied', 'state' => (string) $authorization->getState(), 'iss' => rtrim($this->config->baseUrl, '/')])]);
            }
        }
        if ($user === null) { return $this->html(200, $this->renderer->login($this->state->issue($formBinding, null), false)); }
        $granted = array_values(array_filter($authorization->getScopes(), static fn ($scope): bool => in_array($scope->getIdentifier(), $allowed, true)));
        if ($granted === []) { return $this->html(403, $this->renderer->message('No requested permissions may be delegated.')); }
        $authorization->setScopes($granted);
        if ($request->getMethod() === 'POST' && $action === 'approve') { return $user; }
        return $this->html(200, $this->renderer->consent($authorization->getClient()->getName(), $authorization->getClient()->getIdentifier(),
            array_map(fn ($scope) => $this->scopes->get($scope->getIdentifier()), $granted),
            $this->state->issue($formBinding, $user->getIdentifier())));
    }
    /**
     * @param list<string> $requested
     * @param list<string> $allowed
     */
    private function formBinding(string $binding, array $requested, array $allowed): string
    {
        return hash('sha256', $binding . json_encode(array_values(array_intersect($requested, $allowed)), JSON_THROW_ON_ERROR));
    }
    private function html(int $status, string $body): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store',
            'X-Frame-Options' => 'DENY', 'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'"], $body);
    }
}
