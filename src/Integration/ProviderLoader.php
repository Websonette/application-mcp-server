<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\Integration;

use Websonette\ApplicationMcpServer\Content\Catalog;
use Websonette\ApplicationMcpServer\Tool\Registry;
use Websonette\ApplicationMcpServer\Security\ScopeCatalog;

final class ProviderLoader
{
    private bool $loaded = false;

    /** @param list<ToolProvider> $providers */
    public function __construct(private Registry $tools, private Catalog $content, private array $providers, private ?ScopeCatalog $scopes = null) {}

    public function load(): void
    {
        if ($this->loaded) { return; }
        foreach ($this->providers as $provider) { $provider->register($this->tools, $this->content); }
        if ($this->scopes !== null) {
            foreach ($this->tools->all() as $tool) { $this->scopes->validate($tool->scopes); }
            foreach (array_merge($this->content->resources, $this->content->prompts) as $definition) { $this->scopes->validate($definition['scopes']); }
        }
        $this->loaded = true;
    }
}
