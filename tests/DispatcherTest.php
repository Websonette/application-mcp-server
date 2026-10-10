<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\Tests;

use Websonette\ApplicationMcpServer\Security\Principal;
use Websonette\ApplicationMcpServer\Tests\Support\DatabaseTestCase;
use Websonette\ApplicationMcpServer\Tool\CanonicalJson;
use Websonette\ApplicationMcpServer\Tool\Definition;
use Websonette\ApplicationMcpServer\Tool\Risk;
use Websonette\ApplicationMcpServer\Tool\ToolException;

final class DispatcherTest extends DatabaseTestCase
{
    private int $calls = 0;

    private function register(Risk $risk = Risk::Write, bool $fail = false, bool $invalidOutput = false): void
    {
        $this->tools->add(new Definition('update_item', 'Update a draft using optimistic version checking.', [
            'type' => 'object', 'properties' => ['title' => ['type' => 'string', 'minLength' => 1], 'expected_version' => ['type' => 'integer', 'minimum' => 1]],
            'required' => ['title', 'expected_version'],
        ], [$risk === Risk::Sensitive ? 'publish' : 'write'], $risk,
            function (array $args, Principal $principal) use ($fail, $invalidOutput): array {
                $this->calls++;
                $changed = $this->store->execute('UPDATE test_items SET title = :title, version = version + 1 WHERE id = 1 AND version = :version',
                    ['title' => (string) $args['title'], 'version' => (int) $args['expected_version']]);
                if ($changed !== 1) { throw ToolException::versionConflict(); }
                if ($fail) { throw new \RuntimeException('secret database detail'); }
                return $invalidOutput ? ['version' => 'invalid'] : ['version' => (int) $args['expected_version'] + 1];
            }, ['type' => 'object', 'properties' => ['version' => ['type' => 'integer']], 'required' => ['version']]));
    }

    /** @return array<string, mixed> */
    private function arguments(string $key = 'operation-1'): array { return ['title' => 'New draft', 'expected_version' => 1, 'idempotency_key' => $key]; }

    private function fails(string $code, \Closure $operation): void
    {
        try { $operation(); self::fail('Expected ' . $code); }
        catch (ToolException $e) { self::assertSame($code, $e->errorCode); }
    }

    public function testWriteReplaysOnceAndDoesNotCacheControlFields(): void
    {
        $this->register();
        self::assertSame(['version' => 2], $this->dispatcher->call('update_item', $this->arguments(), $this->principal, 'r1'));
        self::assertSame(['version' => 2], $this->dispatcher->call('update_item', $this->arguments(), $this->principal, 'r2'));
        self::assertSame(1, $this->calls);
        $calls = array_filter($this->admin->activity(), static fn (array $row): bool => $row['operation'] === 'update_item');
        self::assertEqualsCanonicalizing(['replayed', 'success'], array_column($calls, 'outcome'));
        self::assertStringNotContainsString('New draft', json_encode($this->admin->activity(), JSON_THROW_ON_ERROR));
    }

    public function testIdempotencyKeyCannotBeReusedForOtherParameters(): void
    {
        $this->register();
        $this->dispatcher->call('update_item', $this->arguments(), $this->principal, 'r1');
        $this->fails('idempotency_conflict', fn () => $this->dispatcher->call('update_item', array_replace($this->arguments(), ['title' => 'Other']), $this->principal, 'r2'));
        self::assertSame(1, $this->calls);
    }

    public function testKeyIsNamespacedByUserAndTool(): void
    {
        $this->register();
        $this->dispatcher->call('update_item', $this->arguments(), $this->principal, 'r1');
        $other = new Principal('test-client', 'user-2', ['write']);
        $result = $this->dispatcher->call('update_item', array_replace($this->arguments(), ['expected_version' => 2]), $other, 'r2');
        self::assertSame(['version' => 3], $result);
        self::assertSame(2, $this->calls);
    }

    public function testWriteRequiresKeyAndRejectsUnknownArguments(): void
    {
        $this->register();
        $this->fails('invalid_arguments', fn () => $this->dispatcher->call('update_item', ['title' => 'x', 'expected_version' => 1], $this->principal, 'r1'));
        $this->fails('invalid_arguments', fn () => $this->dispatcher->call('update_item', $this->arguments() + ['sql' => 'DROP TABLE'], $this->principal, 'r2'));
        self::assertSame(0, $this->calls);
    }

    public function testScopeAndRevocationBlockEvenCachedWrites(): void
    {
        $this->register();
        $this->fails('insufficient_scope', fn () => $this->dispatcher->call('update_item', $this->arguments(), new Principal('test-client', 'user-1', ['read']), 'r1'));
        $this->dispatcher->call('update_item', $this->arguments(), $this->principal, 'r2');
        $this->admin->disconnectClient('test-client');
        $this->fails('client_revoked', fn () => $this->dispatcher->call('update_item', $this->arguments(), $this->principal, 'r3'));
    }

    public function testHumanApprovalBindsExactPayloadAndIsConsumedOnce(): void
    {
        $this->register(Risk::Sensitive);
        $domain = ['title' => 'New draft', 'expected_version' => 1];
        $ticket = $this->approvals->issue($this->principal, 'update_item', $domain, 'human-1');
        $args = $this->arguments() + ['confirm' => true, 'approval_ticket' => $ticket];
        $this->fails('approval_required', fn () => $this->dispatcher->call('update_item', array_replace($args, ['title' => 'Tampered']), $this->principal, 'r1'));
        self::assertSame(['version' => 2], $this->dispatcher->call('update_item', $args, $this->principal, 'r2'));
        self::assertSame(['version' => 2], $this->dispatcher->call('update_item', $args, $this->principal, 'r3'));
        $this->fails('approval_required', fn () => $this->dispatcher->call('update_item', array_replace($args, ['idempotency_key' => 'new-key']), $this->principal, 'r4'));
        self::assertSame(1, $this->calls);
    }

    public function testApprovalCannotBeBorrowedAndExpires(): void
    {
        $this->register(Risk::Sensitive);
        $ticket = $this->approvals->issue($this->principal, 'update_item', ['title' => 'New draft', 'expected_version' => 1], 'human-1', 5);
        $args = $this->arguments() + ['confirm' => true, 'approval_ticket' => $ticket];
        $other = new Principal('test-client', 'user-2', ['publish']);
        $this->fails('approval_required', fn () => $this->dispatcher->call('update_item', $args, $other, 'r1'));
        $this->clock->offset = 6;
        $this->fails('approval_required', fn () => $this->dispatcher->call('update_item', $args, $this->principal, 'r2'));
    }

    public function testFalseConfirmationAndFabricatedTicketNeverRunHandler(): void
    {
        $this->register(Risk::Sensitive);
        $args = $this->arguments() + ['confirm' => false, 'approval_ticket' => str_repeat('a', 64)];
        $this->fails('invalid_arguments', fn () => $this->dispatcher->call('update_item', $args, $this->principal, 'r1'));
        $this->fails('approval_required', fn () => $this->dispatcher->call('update_item', array_replace($args, ['confirm' => true]), $this->principal, 'r2'));
        self::assertSame(0, $this->calls);
    }

    public function testFailureRollsBackWriteReservationAndApproval(): void
    {
        $this->register(Risk::Sensitive, fail: true);
        $ticket = $this->approvals->issue($this->principal, 'update_item', ['title' => 'New draft', 'expected_version' => 1], 'human-1');
        try { $this->dispatcher->call('update_item', $this->arguments() + ['confirm' => true, 'approval_ticket' => $ticket], $this->principal, 'r1'); self::fail(); }
        catch (\RuntimeException $e) { self::assertSame('secret database detail', $e->getMessage()); }
        self::assertSame('Draft', $this->store->row('SELECT title FROM test_items WHERE id = 1')['title'] ?? null);
        self::assertNull($this->store->row('SELECT * FROM mcp_idempotency'));
        self::assertSame(0, (int) ($this->store->row('SELECT consumed FROM mcp_approvals')['consumed'] ?? -1));
        self::assertStringNotContainsString('secret', json_encode($this->admin->activity(), JSON_THROW_ON_ERROR));
    }

    public function testVersionConflictAndInvalidOutputRollback(): void
    {
        $this->register(invalidOutput: true);
        $this->fails('version_conflict', fn () => $this->dispatcher->call('update_item', array_replace($this->arguments(), ['expected_version' => 9]), $this->principal, 'r1'));
        $this->fails('invalid_output', fn () => $this->dispatcher->call('update_item', $this->arguments(), $this->principal, 'r2'));
        self::assertSame(1, (int) ($this->store->row('SELECT version FROM test_items WHERE id = 1')['version'] ?? 0));
    }

    public function testCanonicalPayloadSortsObjectsButNotLists(): void
    {
        self::assertSame(CanonicalJson::hash(['b' => 2, 'a' => ['z' => 1, 'y' => 2]]), CanonicalJson::hash(['a' => ['y' => 2, 'z' => 1], 'b' => 2]));
        self::assertNotSame(CanonicalJson::hash([1, 2]), CanonicalJson::hash([2, 1]));
        self::assertNotSame(CanonicalJson::hash(['n' => 1]), CanonicalJson::hash(['n' => 1.0]));
    }
}
