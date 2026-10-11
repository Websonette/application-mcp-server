<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\Security;

final class ScopeCatalog
{
    /** @var array<string, ScopeDefinition> */
    private array $definitions = [];

    /** @param list<string>|ScopeProvider $source */
    public function __construct(array|ScopeProvider $source)
    {
        $definitions = $source instanceof ScopeProvider ? $source->getScopes()
            : array_map(static fn (string $id): ScopeDefinition => new ScopeDefinition($id, $id), $source);
        foreach ($definitions as $definition) {
            if (isset($this->definitions[$definition->id])) { throw new \InvalidArgumentException('Duplicate scope: ' . $definition->id); }
            $this->definitions[$definition->id] = $definition;
        }
    }

    /** @return list<string> */
    public function identifiers(): array { return array_keys($this->definitions); }
    /** @return list<ScopeDefinition> */
    public function all(): array { return array_values($this->definitions); }
    public function get(string $id): ScopeDefinition
    {
        return $this->definitions[$id] ?? throw new \InvalidArgumentException('Unknown scope: ' . $id);
    }
    /** @param list<string> $ids */
    public function validate(array $ids): void
    {
        foreach ($ids as $id) { $this->get($id); }
    }
}
