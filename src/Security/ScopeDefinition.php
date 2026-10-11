<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\Security;

final readonly class ScopeDefinition
{
    public function __construct(public string $id, public string $label, public string $description = '')
    {
        Scopes::validate([$id]);
        if ($label === '') { throw new \InvalidArgumentException('Scope label cannot be empty.'); }
    }
}
