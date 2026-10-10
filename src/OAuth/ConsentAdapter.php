<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\OAuth;

use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

interface ConsentAdapter
{
    /**
     * Return a login/consent page or redirect until a human has approved the displayed client and scopes.
     * On approval, return User. This adapter MUST check the app's user permissions and validate CSRF.
     * Request scopes can be reduced before approval. The package does not auto-approve consent.
     */
    public function resolve(ServerRequestInterface $request, AuthorizationRequestInterface $authorization): User|ResponseInterface;
}
