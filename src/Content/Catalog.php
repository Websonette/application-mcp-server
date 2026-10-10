<?php
declare(strict_types=1);

namespace Websonette\ApplicationMcpServer\Content;

use Websonette\ApplicationMcpServer\Security\Principal;
use Websonette\ApplicationMcpServer\Security\Scopes;

final class Catalog
{
    /** @var array<string, array{name: string, description: string, scopes: list<string>, load: \Closure(Principal): string}> */
    public array $resources = [];
    /** @var array<string, array{description: string, scopes: list<string>, load: \Closure(Principal): string}> */
    public array $prompts = [];

    /** @param list<string> $scopes @param \Closure(Principal): string $load */
    public function resource(string $uri, string $name, string $description, array $scopes, \Closure $load): void
    {
        Scopes::validate($scopes);
        if ($scopes === [] || isset($this->resources[$uri]) || !preg_match('~^[a-z][a-z0-9+.-]*://~i', $uri)) {
            throw new \InvalidArgumentException('Resources require a unique absolute URI and scopes.');
        }
        $this->resources[$uri] = compact('name', 'description', 'scopes', 'load');
    }

    /** @param list<string> $scopes @param \Closure(Principal): string $load */
    public function prompt(string $name, string $description, array $scopes, \Closure $load): void
    {
        Scopes::validate($scopes);
        if ($scopes === [] || isset($this->prompts[$name]) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name)) {
            throw new \InvalidArgumentException('Prompts require a unique stable name and scopes.');
        }
        $this->prompts[$name] = compact('description', 'scopes', 'load');
    }
}
