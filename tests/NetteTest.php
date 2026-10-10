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
