<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\Tests;

use Nette\DI\Compiler;
use PHPUnit\Framework\TestCase;
use Websonette\ApplicationMcpServer\Content\Catalog;
use Websonette\ApplicationMcpServer\Http\Handler;
use Websonette\ApplicationMcpServer\Integration\McpExtension;
use Websonette\ApplicationMcpServer\Integration\ToolProvider;
use Websonette\ApplicationMcpServer\Persistence\SchemaMigrator;
use Websonette\ApplicationMcpServer\Security\Principal;
use Websonette\ApplicationMcpServer\Tool\Definition;
use Websonette\ApplicationMcpServer\Tool\Registry;
use Websonette\ApplicationMcpServer\Tool\Risk;

final class NetteTest extends TestCase
{
    public function testAutomaticOAuthAndNetteTransactionsShareTheApplicationConnection(): void
    {
        $directory = sys_get_temp_dir() . '/mcp-di-' . bin2hex(random_bytes(8));
        (new \Websonette\ApplicationMcpServer\Console\KeyGenerator())->generate($directory);
        try {
            $compiler = new Compiler();
            $class = 'AutoMcpContainer_' . bin2hex(random_bytes(8));
            $compiler->setClassName($class);
            $compiler->addExtension('mcp', new McpExtension());
            $compiler->addConfig([
                'services' => [
                    'database' => ['factory' => \Nette\Database\Connection::class, 'arguments' => ['sqlite::memory:']],
                    'identity' => TestIdentity::class,
                    'state' => MemoryConsentState::class,
                    'fetcher' => TestFetcher::class,
                    'tools' => ExampleProvider::class,
                ],
                'mcp' => ['baseUrl' => 'https://app.example', 'keys' => ['directory' => $directory],
                    'identity' => '@identity', 'scopes' => ['read', 'write'], 'consent' => ['state' => '@state'],
                    'oauth' => ['clients' => ['metadataFetcher' => '@fetcher', 'dcr' => true]]],
            ]);
            eval($compiler->compile());
            $container = new $class();
            self::assertInstanceOf(\Nette\DI\Container::class, $container);
            $container->initialize();
            self::assertInstanceOf(Handler::class, $container->getService('mcp.http'));
            $store = $container->getService('mcp.store');
            self::assertInstanceOf(\Websonette\ApplicationMcpServer\Persistence\Store::class, $store);
            $connection = $container->getService('database');
            self::assertInstanceOf(\Nette\Database\Connection::class, $connection);
            self::assertSame($connection->getPdo(), $store->pdo);
            $migrator = $container->getService('mcp.migrator');
            self::assertInstanceOf(SchemaMigrator::class, $migrator);
            self::assertFalse($migrator->installed());
            $connection->query('CREATE TABLE business (id INTEGER)');
            try {
                $store->transaction(function () use ($connection): void {
                    $connection->transaction(function () use ($connection): void { $connection->query('INSERT INTO business VALUES (1)'); });
                    throw new \RuntimeException('Rollback.');
                });
            } catch (\RuntimeException) { self::assertSame(0, $connection->query('SELECT COUNT(*) FROM business')->fetchField()); }
            $oauth = $container->getService('mcp.oauth');
            self::assertInstanceOf(\Websonette\ApplicationMcpServer\OAuth\Server::class, $oauth);
            self::assertTrue($oauth->metadata()['client_id_metadata_document_supported']);
            self::assertSame('https://app.example/oauth/register', $oauth->metadata()['registration_endpoint']);
        } finally {
            foreach (['private.pem', 'public.pem', 'encryption.key'] as $file) { unlink($directory . '/' . $file); }
            rmdir($directory);
        }
    }
    public function testExtensionWiresProvidersWithoutImplicitMigration(): void
    {
        $compiler = new Compiler();
        $class = 'TestMcpContainer_' . bin2hex(random_bytes(8));
        $compiler->setClassName($class);
        $compiler->addExtension('mcp', new McpExtension());
        $compiler->addConfig([
            'services' => [
                'database' => ['factory' => \PDO::class, 'arguments' => ['sqlite::memory:']],
                'tools' => ['factory' => ExampleProvider::class],
            ],
            'mcp' => ['baseUrl' => 'http://127.0.0.1:8080', 'development' => true, 'pdo' => '@database',
                'authenticator' => '@mcp.tokens', 'scopes' => ['read']],
        ]);
        $code = $compiler->compile();
        eval($code);
        $container = new $class();
        self::assertInstanceOf(\Nette\DI\Container::class, $container);
        $container->initialize();
        self::assertInstanceOf(Handler::class, $container->getService('mcp.http'));
        $tools = $container->getService('mcp.tools');
        self::assertInstanceOf(Registry::class, $tools);
        self::assertCount(1, $tools->all());
        $migrator = $container->getService('mcp.migrator');
        self::assertInstanceOf(SchemaMigrator::class, $migrator);
        self::assertFalse($migrator->installed());
    }
}

final class ExampleProvider implements ToolProvider
{
    public function register(Registry $tools, Catalog $content): void
    {
        $tools->add(new Definition('hello', 'Hello.', ['type' => 'object'], ['read'], Risk::Read, static fn (array $args, Principal $p): array => ['hello' => 'world']));
    }
}
