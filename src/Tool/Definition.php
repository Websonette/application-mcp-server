<?php
declare(strict_types=1);

namespace Websonette\ApplicationMcpServer\Tool;

use Websonette\ApplicationMcpServer\Security\Principal;
use Websonette\ApplicationMcpServer\Security\Scopes;

final readonly class Definition
{
    /**
     * @param array<string, mixed> $inputSchema
     * @param list<string> $scopes
     * @param \Closure(array<string, mixed>, Principal): array<string, mixed> $handler
     * @param array<string, mixed>|null $outputSchema
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $inputSchema,
        public array $scopes,
        public Risk $risk,
        public \Closure $handler,
        public ?array $outputSchema = null,
    ) {
        if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $name) || $description === '' || $scopes === []
            || ($inputSchema['type'] ?? null) !== 'object') {
            throw new \InvalidArgumentException('Tools require a stable name, description, scopes and an object input schema.');
        }
        Scopes::validate($scopes);
        foreach (['confirm', 'approval_ticket', 'idempotency_key'] as $reserved) {
            if (isset($inputSchema['properties'][$reserved])) {
                throw new \InvalidArgumentException('Reserved control argument: ' . $reserved);
            }
        }
    }

    /** @return array<string, mixed> */
    public function schema(): array
    {
        $schema = $this->inputSchema;
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];
        if (!is_array($properties) || !is_array($required)) {
            throw new \LogicException('Schema properties and required must be arrays.');
        }
        if ($this->risk !== Risk::Read) {
            $properties['idempotency_key'] = ['type' => 'string', 'minLength' => 1, 'maxLength' => 128];
            $required[] = 'idempotency_key';
        }
        if ($this->risk === Risk::Sensitive) {
            $properties['confirm'] = ['type' => 'boolean', 'const' => true];
            $properties['approval_ticket'] = ['type' => 'string', 'minLength' => 64, 'maxLength' => 64];
            $required[] = 'confirm';
            $required[] = 'approval_ticket';
        }
        $schema['properties'] = $properties === [] ? new \stdClass() : $properties;
        $schema['required'] = array_values(array_unique($required));
        $schema['additionalProperties'] = false;
        return $schema;
    }
}
