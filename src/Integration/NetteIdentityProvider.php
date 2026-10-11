<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\Integration;

use Nette\Security\AuthenticationException;
use Nette\Security\User as NetteUser;
use Psr\Http\Message\ServerRequestInterface;
use Websonette\ApplicationMcpServer\OAuth\IdentityProvider;
use Websonette\ApplicationMcpServer\OAuth\User;
use Websonette\ApplicationMcpServer\Security\ScopeCatalog;

/** Uses the application's authenticator and authorizator. Configure resource mcp and privileges equal to scope IDs. */
final readonly class NetteIdentityProvider implements IdentityProvider
{
    public function __construct(private NetteUser $user, private ScopeCatalog $scopes) {}
    public function currentUser(ServerRequestInterface $request): ?User
    {
        $id = $this->user->getId();
        return $this->user->isLoggedIn() && $id !== null && (string) $id !== '' ? new User((string) $id) : null;
    }
    public function login(ServerRequestInterface $request, string $username, #[\SensitiveParameter] string $password): ?User
    {
        try { $this->user->login($username, $password); } catch (AuthenticationException) { return null; }
        return $this->currentUser($request);
    }
    /** @return list<string> */
    public function allowedScopes(User $user): array
    {
        if (!$this->user->isLoggedIn() || (string) $this->user->getId() !== $user->getIdentifier()) { return []; }
        return array_values(array_filter($this->scopes->identifiers(), fn (string $scope): bool => $this->user->isAllowed('mcp', $scope)));
    }
}
