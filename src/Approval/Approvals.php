<?php
declare(strict_types=1);

namespace Websonette\ApplicationMcpServer\Approval;

use Websonette\ApplicationMcpServer\Persistence\Store;
use Websonette\ApplicationMcpServer\Security\Principal;
use Websonette\ApplicationMcpServer\Tool\CanonicalJson;
use Websonette\ApplicationMcpServer\Tool\ToolException;

final readonly class Approvals
{
    public function __construct(private Store $store)
    {
    }

    /**
     * Call only from the application's authenticated, CSRF-protected human approval flow.
     * The application authorizes the approver and shows the complete parameters.
     * @param array<string, mixed> $arguments Domain arguments (including expected_version), without control fields.
     */
    public function issue(Principal $principal, string $tool, array $arguments, string $approverId, int $ttl = 300): string
    {
        if ($approverId === '' || strlen($approverId) > 128 || $ttl < 1 || $ttl > 900 || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $tool)) {
            throw new \InvalidArgumentException('Approver and a ticket lifetime of 1–900 seconds are required.');
        }
        $this->store->assertClientActive($principal->clientId);
        $ticket = bin2hex(random_bytes(32));
        $this->store->transaction(function () use ($ticket, $principal, $tool, $arguments, $approverId, $ttl): void {
            $this->store->execute(
                'INSERT INTO mcp_approvals (ticket_hash, principal_key, tool, payload_hash, approver_id, expires_at, consumed) VALUES (:ticket, :principal, :tool, :payload, :approver, :expires, 0)',
                ['ticket' => hash('sha256', $ticket), 'principal' => $principal->key(), 'tool' => $tool,
                    'payload' => CanonicalJson::hash($arguments), 'approver' => $approverId, 'expires' => $this->store->now() + $ttl],
            );
            $this->store->audit(new Principal($principal->clientId, $approverId, []), 'approve:' . $tool, 'approved', bin2hex(random_bytes(16)));
        });
        return $ticket;
    }

    /** @param array<string, mixed> $arguments */
    public function consume(string $ticket, Principal $principal, string $tool, array $arguments): void
    {
        $changed = $this->store->execute(
            'UPDATE mcp_approvals SET consumed = 1 WHERE ticket_hash = :ticket AND principal_key = :principal AND tool = :tool AND payload_hash = :payload AND expires_at > :now AND consumed = 0',
            ['ticket' => hash('sha256', $ticket), 'principal' => $principal->key(), 'tool' => $tool,
                'payload' => CanonicalJson::hash($arguments), 'now' => $this->store->now()],
        );
        if ($changed !== 1) {
            throw new ToolException('approval_required', 'A valid, unused approval for these exact parameters is required.');
        }
    }
}
