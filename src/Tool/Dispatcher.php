<?php
declare(strict_types=1);

namespace Websonette\ApplicationMcpServer\Tool;

use Opis\JsonSchema\Validator;
use Websonette\ApplicationMcpServer\Approval\Approvals;
use Websonette\ApplicationMcpServer\Persistence\Store;
use Websonette\ApplicationMcpServer\Security\Principal;

final readonly class Dispatcher
{
    public function __construct(private Registry $registry, private Store $store, private Approvals $approvals)
    {
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function call(string $name, array $arguments, Principal $principal, string $requestId): array
    {
        $tool = $this->registry->get($name);
        try {
            $this->store->assertClientActive($principal->clientId);
            if (!$principal->permits($tool->scopes)) {
                throw new ToolException('insufficient_scope', 'The operation requires additional permissions.');
            }
            $validator = new Validator();
            $data = json_decode(json_encode((object) $arguments, JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR);
            $schema = json_decode(json_encode($tool->schema(), JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR);
            if (!$validator->validate($data, $schema)->isValid()) {
                throw new ToolException('invalid_arguments', 'Arguments do not match the tool input schema.');
            }
            $domain = $arguments;
            unset($domain['confirm'], $domain['approval_ticket'], $domain['idempotency_key']);
            $run = function () use ($tool, $domain, $principal, $validator): array {
                $result = ($tool->handler)($domain, $principal);
                if ($tool->outputSchema !== null) {
                    $output = json_decode(json_encode((object) $result, JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR);
                    $schema = json_decode(json_encode($tool->outputSchema, JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR);
                    if (!$validator->validate($output, $schema)->isValid()) {
                        throw new ToolException('invalid_output', 'The handler returned an invalid result.');
                    }
                }
                return $result;
            };
            if ($tool->risk === Risk::Read) {
                $result = $run();
                $this->store->audit($principal, $name, 'success', $requestId);
                return $result;
            }
            return $this->store->transaction(function () use ($tool, $arguments, $domain, $principal, $name, $requestId, $run): array {
                $key = CanonicalJson::hash([$principal->key(), $name, $arguments['idempotency_key']]);
                $payload = CanonicalJson::hash($domain);
                $previous = $this->store->row('SELECT payload_hash, result_json FROM mcp_idempotency WHERE key_hash = :key', ['key' => $key]);
                if ($previous !== null) {
                    if (!hash_equals((string) $previous['payload_hash'], $payload)) {
                        throw new ToolException('idempotency_conflict', 'This key was already used with different parameters.');
                    }
                    $this->store->audit($principal, $name, 'replayed', $requestId);
                    /** @var array<string, mixed> */
                    return json_decode((string) $previous['result_json'], true, 32, JSON_THROW_ON_ERROR);
                }
                // Reserve before invoking the handler. A concurrent request cannot perform the same write.
                $this->store->execute('INSERT INTO mcp_idempotency (key_hash, payload_hash, result_json, created_at) VALUES (:key, :payload, :result, :time)',
                    ['key' => $key, 'payload' => $payload, 'result' => '{}', 'time' => $this->store->now()]);
                if ($tool->risk === Risk::Sensitive) {
                    $this->approvals->consume((string) $arguments['approval_ticket'], $principal, $name, $domain);
                }
                $result = $run();
                $this->store->execute('UPDATE mcp_idempotency SET result_json = :result WHERE key_hash = :key',
                    ['key' => $key, 'result' => json_encode((object) $result, JSON_THROW_ON_ERROR)]);
                $this->store->audit($principal, $name, 'success', $requestId);
                return $result;
            });
        } catch (\Throwable $e) {
            $this->store->audit($principal, $name, $e instanceof ToolException ? $e->errorCode : 'internal_error', $requestId);
            throw $e;
        }
    }
}
