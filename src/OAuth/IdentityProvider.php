<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\OAuth;

use Psr\Http\Message\ServerRequestInterface;

interface IdentityProvider
{
    public function currentUser(ServerRequestInterface $request): ?User;
    public function login(ServerRequestInterface $request, string $username, #[\SensitiveParameter] string $password): ?User;
    /**
     * Current permissions the user may delegate, not merely the requested scopes.
     * @return list<string>
     */
    public function allowedScopes(User $user): array;
}
