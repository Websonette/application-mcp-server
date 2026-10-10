<?php
declare(strict_types=1);

namespace Websonette\ApplicationMcpServer\Http;

use Mcp\Server\Session\SessionStoreInterface;
use Symfony\Component\Uid\Uuid;
use Websonette\ApplicationMcpServer\Persistence\Store;
use Websonette\ApplicationMcpServer\Security\Principal;
use Websonette\ApplicationMcpServer\Tool\CanonicalJson;

final readonly class SessionStore implements SessionStoreInterface
{
    private string $owner;

    public function __construct(private Store $store, Principal $principal)
    {
        $scopes = $principal->scopes;
        sort($scopes);
        $this->owner = CanonicalJson::hash([$principal->key(), $scopes]);
    }

    public function exists(Uuid $id): bool
    {
        return $this->read($id) !== false;
    }

    public function read(Uuid $id): string|false
    {
        $row = $this->store->row('SELECT payload FROM mcp_sessions WHERE session_id = :id AND principal_key = :owner AND expires_at > :now',
            ['id' => $id->toRfc4122(), 'owner' => $this->owner, 'now' => $this->store->now()]);
        return $row === null ? false : (string) $row['payload'];
    }

    public function write(Uuid $id, string $data): bool
    {
        $params = ['id' => $id->toRfc4122(), 'owner' => $this->owner, 'payload' => $data, 'expires' => $this->store->now() + 3600];
        $row = $this->store->row('SELECT principal_key FROM mcp_sessions WHERE session_id = :id', ['id' => $params['id']]);
        if ($row !== null) {
            if ($row['principal_key'] !== $this->owner) { return false; }
            $this->store->execute('UPDATE mcp_sessions SET payload = :payload, expires_at = :expires WHERE session_id = :id AND principal_key = :owner', $params);
            return true;
        }
        try {
            $this->store->execute('INSERT INTO mcp_sessions (session_id, principal_key, payload, expires_at) VALUES (:id, :owner, :payload, :expires)', $params);
            return true;
        } catch (\PDOException $e) {
            if (str_starts_with((string) $e->getCode(), '23')) { return false; }
            throw $e;
        }
    }

    public function destroy(Uuid $id): bool
    {
        $this->store->execute('DELETE FROM mcp_sessions WHERE session_id = :id AND principal_key = :owner',
            ['id' => $id->toRfc4122(), 'owner' => $this->owner]);
        return true;
    }

    /** @return list<Uuid> */
    public function gc(): array
    {
        $statement = $this->store->pdo->prepare('SELECT session_id FROM mcp_sessions WHERE principal_key = :owner AND expires_at <= :now');
        $params = ['owner' => $this->owner, 'now' => $this->store->now()];
        $statement->execute($params);
        /** @var list<string> $ids */
        $ids = $statement->fetchAll(\PDO::FETCH_COLUMN);
        $this->store->execute('DELETE FROM mcp_sessions WHERE principal_key = :owner AND expires_at <= :now', $params);
        return array_map(Uuid::fromString(...), $ids);
    }
}
