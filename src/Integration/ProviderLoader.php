<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\Integration;

use Websonette\ApplicationMcpServer\Content\Catalog;
use Websonette\ApplicationMcpServer\Tool\Registry;

final class ProviderLoader
{
    private bool $loaded = false;

    /** @param list<ToolProvider> $providers */
    public function __construct(private Registry $tools, private Catalog $content, private array $providers) {}

    public function load(): void
    {
        if ($this->loaded) { return; }
        foreach ($this->providers as $provider) { $provider->register($this->tools, $this->content); }
        $this->loaded = true;
    }
}
