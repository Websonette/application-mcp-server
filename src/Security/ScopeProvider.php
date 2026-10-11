<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\Security;

interface ScopeProvider
{
    /** @return iterable<ScopeDefinition> */
    public function getScopes(): iterable;
}
