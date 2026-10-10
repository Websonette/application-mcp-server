<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\Integration;

use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\Reference;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use Websonette\ApplicationMcpServer\Admin\Administration;
use Websonette\ApplicationMcpServer\Approval\Approvals;
use Websonette\ApplicationMcpServer\Config;
use Websonette\ApplicationMcpServer\Content\Catalog;
use Websonette\ApplicationMcpServer\Http\Handler;
use Websonette\ApplicationMcpServer\Persistence\SchemaMigrator;
use Websonette\ApplicationMcpServer\Persistence\Store;
use Websonette\ApplicationMcpServer\Security\Tokens;
use Websonette\ApplicationMcpServer\SystemClock;
use Websonette\ApplicationMcpServer\Tool\Dispatcher;
use Websonette\ApplicationMcpServer\Tool\Registry;

final class McpExtension extends CompilerExtension
{
    public function getConfigSchema(): Schema
    {
        return Expect::structure([
            'baseUrl' => Expect::string()->required(),
            'name' => Expect::string('Websonette application'),
            'version' => Expect::string('0.1.0'),
            'development' => Expect::bool(false),
            'allowedOrigins' => Expect::listOf('string')->default([]),
            'maxBodyBytes' => Expect::int(1048576)->min(1),
            'requestsPerMinute' => Expect::int(120)->min(1),
            'scopes' => Expect::listOf('string')->required(),
            'pdo' => Expect::string()->required(),
            'authenticator' => Expect::string()->required(),
            'oauth' => Expect::string()->nullable(),
        ])->castTo('array');
    }

    public function loadConfiguration(): void
    {
        /** @var array{baseUrl: string, name: string, version: string, development: bool, allowedOrigins: list<string>, maxBodyBytes: int, requestsPerMinute: int, scopes: list<string>, pdo: string, authenticator: string, oauth: ?string} $config */
        $config = $this->getConfig();
        $builder = $this->getContainerBuilder();
        $service = fn (string $name): Reference => new Reference($this->prefix($name));
        $builder->addDefinition($this->prefix('config'))->setFactory(Config::class, [
            $config['baseUrl'], $config['name'], $config['version'], $config['development'], $config['allowedOrigins'],
            $config['maxBodyBytes'], $config['requestsPerMinute'],
        ]);
        $builder->addDefinition($this->prefix('clock'))->setFactory(SystemClock::class);
        $builder->addDefinition($this->prefix('store'))->setFactory(Store::class, [new Reference(ltrim($config['pdo'], '@')), $service('clock')]);
        $builder->addDefinition($this->prefix('migrator'))->setFactory(SchemaMigrator::class, [new Reference(ltrim($config['pdo'], '@'))]);
        $builder->addDefinition($this->prefix('tools'))->setFactory(Registry::class);
        $builder->addDefinition($this->prefix('content'))->setFactory(Catalog::class);
        $builder->addDefinition($this->prefix('approvals'))->setFactory(Approvals::class, [$service('store')]);
        $builder->addDefinition($this->prefix('admin'))->setFactory(Administration::class, [$service('store')]);
        $builder->addDefinition($this->prefix('tokens'))->setFactory(Tokens::class, [$service('store'), $service('config')]);
        $builder->addDefinition($this->prefix('dispatcher'))->setFactory(Dispatcher::class, [$service('tools'), $service('store'), $service('approvals')]);
        $builder->addDefinition($this->prefix('providers'))->setFactory(ProviderLoader::class, [$service('tools'), $service('content'), []]);
        $builder->addDefinition($this->prefix('http'))->setFactory(Handler::class, [
            $service('config'), $service('store'), new Reference(ltrim($config['authenticator'], '@')), $service('tools'),
            $service('dispatcher'), $service('content'), $config['scopes'],
            $config['oauth'] === null ? null : new Reference(ltrim($config['oauth'], '@')),
        ])->addSetup('?->load()', [$service('providers')]);
    }

    public function beforeCompile(): void
    {
        $builder = $this->getContainerBuilder();
        $providers = array_map(static fn (string $name): Reference => new Reference($name), array_keys($builder->findByType(ToolProvider::class)));
        $definition = $builder->getDefinition($this->prefix('providers'));
        if (!$definition instanceof \Nette\DI\Definitions\ServiceDefinition) { throw new \LogicException('Invalid provider service definition.'); }
        $definition->setArgument(2, $providers);
    }
}
