<?php
declare(strict_types=1);
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Websonette\ApplicationMcpServer\OAuth\ConsentAdapter;
use Websonette\ApplicationMcpServer\OAuth\User;

final class DemoConsent implements ConsentAdapter
{
    public function resolve(ServerRequestInterface $request, AuthorizationRequestInterface $authorization): User|ResponseInterface
    {
        $password = getenv('MCP_DEMO_PASSWORD');
        if ($password === false || strlen($password) < 12) { return new Response(503, [], 'Set a unique MCP_DEMO_PASSWORD of at least 12 characters. Local demo only.'); }
        $_SESSION['mcp_csrf'] ??= bin2hex(random_bytes(32));
        $form = $request->getParsedBody();
        if ($request->getMethod() === 'POST' && is_array($form)) {
            if (!is_string($form['csrf'] ?? null) || !hash_equals($_SESSION['mcp_csrf'], $form['csrf'])
                || !is_string($form['password'] ?? null) || !hash_equals($password, $form['password']) || ($form['approve'] ?? '') !== 'yes') {
                return new Response(403, [], 'Consent denied.');
            }
            unset($_SESSION['mcp_csrf']);
            return new User('demo-user');
        }
        $escape = static fn (string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $name = $escape($authorization->getClient()->getName());
        $scopes = $escape(implode(', ', array_map(static fn ($s): string => $s->getIdentifier(), $authorization->getScopes())));
        return new Response(200, ['Content-Type' => 'text/html; charset=utf-8', 'Content-Security-Policy' => "default-src 'none'; form-action 'self'; frame-ancestors 'none'"],
            '<!doctype html><html lang="en"><meta charset="utf-8"><title>Local demo consent</title><h1>Allow ' . $name . '?</h1><p>Requested scopes: ' . $scopes
            . '</p><p>This is local demo login and consent, not production authentication.</p><form method="post"><input type="hidden" name="csrf" value="' . $escape($_SESSION['mcp_csrf'])
            . '"><label>Demo password <input type="password" name="password" required autocomplete="current-password"></label><button name="approve" value="yes">Sign in and approve</button><button name="approve" value="no">Deny</button></form></html>');
    }
}
