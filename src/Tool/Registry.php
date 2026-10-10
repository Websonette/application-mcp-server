<?php
declare(strict_types=1);

namespace Websonette\ApplicationMcpServer\Tool;

final class Registry
{
    /** @var array<string, Definition> */
    private array $tools = [];

    public function add(Definition $tool): void
    {
        if (isset($this->tools[$tool->name])) {
            throw new \LogicException('Duplicate tool: ' . $tool->name);
        }
        $this->tools[$tool->name] = $tool;
    }

    public function get(string $name): Definition
    {
        return $this->tools[$name] ?? throw new ToolException('unknown_tool', 'Unknown tool.');
    }

    /** @return list<Definition> */
    public function all(): array
    {
        return array_values($this->tools);
    }
}
