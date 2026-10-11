<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\OAuth;

use Websonette\ApplicationMcpServer\Security\ScopeDefinition;

interface ConsentRenderer
{
    public function login(string $token, bool $failed): string;
    /** @param list<ScopeDefinition> $scopes */
    public function consent(string $clientName, string $clientId, array $scopes, string $token): string;
    public function message(string $message): string;
}
