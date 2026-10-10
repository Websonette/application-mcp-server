<?php
declare(strict_types=1);

namespace Websonette\ApplicationMcpServer\Security;

use Psr\Http\Message\ServerRequestInterface;

interface Authenticator
{
    /** @throws AuthenticationException */
    public function authenticate(ServerRequestInterface $request): Principal;
}
