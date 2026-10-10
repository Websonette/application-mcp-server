<?php
declare(strict_types=1);

namespace Websonette\ApplicationMcpServer\Persistence;

use Psr\Clock\ClockInterface;
use Websonette\ApplicationMcpServer\Security\Principal;
use Websonette\ApplicationMcpServer\Tool\ToolException;

final readonly class Store
{
    public function __construct(public \PDO $pdo, private ClockInterface $clock)
    {
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    /**
     * @param array<string, scalar|null> $params
     * @return array<string, mixed>|null
     */
    public function row(string $sql, array $params = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @param array<string, scalar|null> $params */
    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->rowCount();
    }

    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    public function transaction(\Closure $operation): mixed
    {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('MCP dispatch owns its transaction. Invoke it outside an application transaction.');
        }
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
    }

    public function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    public function audit(Principal $principal, string $operation, string $outcome, string $requestId): void
    {
        $this->execute(
            'INSERT INTO mcp_audit_log (id, occurred_at, client_id, user_id, operation, outcome, request_id) VALUES (:id, :time, :client, :user, :operation, :outcome, :request)',
            ['id' => bin2hex(random_bytes(16)), 'time' => $this->now(), 'client' => $principal->clientId,
                'user' => $principal->userId, 'operation' => $operation, 'outcome' => $outcome, 'request' => $requestId],
        );
    }

    public function rateLimit(Principal $principal, int $limit): bool
    {
        $key = $principal->key();
        $window = intdiv($this->now(), 60);
        if ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $sql = 'INSERT INTO mcp_rate_limits (principal_key, window_id, hits) VALUES (:key, :window, 1) ON CONFLICT(principal_key) DO UPDATE SET hits = CASE WHEN window_id = :window2 THEN hits + 1 ELSE 1 END, window_id = :window3';
        } else {
            $sql = 'INSERT INTO mcp_rate_limits (principal_key, window_id, hits) VALUES (:key, :window, 1) ON DUPLICATE KEY UPDATE hits = IF(window_id = :window2, hits + 1, 1), window_id = :window3';
        }
        $this->execute($sql, ['key' => $key, 'window' => $window, 'window2' => $window, 'window3' => $window]);
        $row = $this->row('SELECT hits FROM mcp_rate_limits WHERE principal_key = :key', ['key' => $key]);
        return $row !== null && (int) $row['hits'] <= $limit;
    }

    /** @return list<array<string, mixed>> */
    public function recentActivity(int $limit = 40): array
    {
        $limit = max(1, min(100, $limit));
        $statement = $this->pdo->prepare('SELECT * FROM mcp_audit_log ORDER BY occurred_at DESC, id DESC LIMIT ' . $limit);
        $statement->execute();
        /** @var list<array<string, mixed>> */
        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function assertClientActive(string $id): void
    {
        if ($this->row('SELECT id FROM mcp_clients WHERE id = :id AND revoked = 0', ['id' => $id]) === null) {
            throw new ToolException('client_revoked', 'Client access is revoked.');
        }
    }
}
