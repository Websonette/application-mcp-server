<?php
declare(strict_types=1);

namespace Websonette\ApplicationMcpServer\Persistence;

final readonly class SchemaMigrator
{
    public const VERSION = 2;

    public function __construct(private \PDO $pdo)
    {
    }

    public function installed(): bool
    {
        try {
            $statement = $this->pdo->prepare('SELECT version FROM mcp_schema_migrations WHERE version = :version');
            $statement->execute(['version' => self::VERSION]);
            return $statement->fetchColumn() !== false;
        } catch (\PDOException) {
            return false;
        }
    }

    public function migrate(): void
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if (!in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new \LogicException('Supported persistence drivers are SQLite and MySQL/MariaDB.');
        }
        $expected = [
            'mcp_schema_migrations' => 'version',
            'mcp_sessions' => 'session_id, principal_key, payload, expires_at',
            'mcp_clients' => 'id, name, redirect_uris, scopes, secret_hash, confidential, revoked, created_at',
            'mcp_access_tokens' => 'id, client_id, user_id, scopes, expires_at, revoked',
            'mcp_refresh_tokens' => 'id, access_token_id, expires_at, revoked',
            'mcp_authorization_codes' => 'id, client_id, expires_at, revoked',
            'mcp_approvals' => 'ticket_hash, principal_key, tool, payload_hash, approver_id, expires_at, consumed',
            'mcp_idempotency' => 'key_hash, payload_hash, result_json, created_at',
            'mcp_audit_log' => 'id, occurred_at, client_id, user_id, operation, outcome, request_id',
            'mcp_rate_limits' => 'principal_key, window_id, hits',
            'mcp_client_metadata' => 'client_id, expires_at, auth_method, application_type',
        ];
        foreach ($expected as $table => $columns) {
            $exists = $this->pdo->prepare($driver === 'sqlite'
                ? "SELECT name FROM sqlite_master WHERE type = 'table' AND name = :name"
                : 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :name');
            $exists->execute(['name' => $table]);
            if ($exists->fetchColumn() === false) { continue; }
            try { $this->pdo->query('SELECT ' . $columns . ' FROM ' . $table . ' WHERE 1 = 0'); }
            catch (\PDOException $error) {
                throw new \LogicException('Incompatible legacy MCP schema. Back up and migrate it explicitly before installing the package schema.', previous: $error);
            }
        }
        foreach (range(1, self::VERSION) as $version) {
            try {
                $check = $this->pdo->prepare('SELECT version FROM mcp_schema_migrations WHERE version = :version');
                $check->execute(['version' => $version]);
                if ($check->fetchColumn() !== false) { continue; }
            } catch (\PDOException) {
                // A fresh database has no migration ledger yet.
            }
            $file = str_pad((string) $version, 3, '0', STR_PAD_LEFT);
            $sql = file_get_contents(dirname(__DIR__, 2) . '/resources/migrations/' . $file . '.' . $driver . '.sql');
            if ($sql === false) {
                throw new \RuntimeException('Migration resource missing.');
            }
            // Statements are repeatable. MySQL DDL is not transactionally rolled back.
            foreach (explode(';', $sql) as $statement) {
                if (trim($statement) !== '') {
                    $this->pdo->exec($statement);
                }
            }
            $this->pdo->exec($driver === 'sqlite'
                ? 'INSERT OR IGNORE INTO mcp_schema_migrations(version) VALUES (' . $version . ')'
                : 'INSERT IGNORE INTO mcp_schema_migrations(version) VALUES (' . $version . ')');
        }
    }
}
