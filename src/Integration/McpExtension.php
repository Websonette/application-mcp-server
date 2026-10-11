<?php
declare(strict_types=1);
namespace Websonette\ApplicationMcpServer\Integration;

use Nette\DI\CompilerExtension;
use Nette\DI\Definitions\Reference;
use Nette\DI\Definitions\Statement;
use Nette\Schema\Expect;
use Nette\Schema\Schema;
use Websonette\ApplicationMcpServer\Admin\Administration;
use Websonette\ApplicationMcpServer\Approval\Approvals;
use Websonette\ApplicationMcpServer\Config;
use Websonette\ApplicationMcpServer\Console\Context;
use Websonette\ApplicationMcpServer\Content\Catalog;
use Websonette\ApplicationMcpServer\Http\Handler;
use Websonette\ApplicationMcpServer\OAuth\BearerAuthenticator;
use Websonette\ApplicationMcpServer\OAuth\ClientRegistration;
use Websonette\ApplicationMcpServer\OAuth\DefaultConsent;
use Websonette\ApplicationMcpServer\OAuth\HtmlConsentRenderer;
use Websonette\ApplicationMcpServer\OAuth\HttpsMetadataFetcher;
use Websonette\ApplicationMcpServer\OAuth\KeyMaterial;
use Websonette\ApplicationMcpServer\OAuth\Repositories;
use Websonette\ApplicationMcpServer\OAuth\Server;
use Websonette\ApplicationMcpServer\Persistence\SchemaMigrator;
use Websonette\ApplicationMcpServer\Persistence\Store;
use Websonette\ApplicationMcpServer\Security\ScopeCatalog;
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
            'version' => Expect::string('0.2.0'),
            'development' => Expect::bool(false),
            'allowedOrigins' => Expect::listOf('string')->default([]),
            'maxBodyBytes' => Expect::int(1048576)->min(1),
            'requestsPerMinute' => Expect::int(120)->min(1),
            'scopes' => Expect::anyOf(Expect::listOf('string'), Expect::string())->required(),
            'database' => Expect::string()->nullable(),
            'pdo' => Expect::string()->nullable(),
            'identity' => Expect::string()->nullable(),
            'keys' => Expect::structure(['directory' => Expect::string()->nullable()])->castTo('array'),
            'consent' => Expect::structure(['state' => Expect::string()->nullable(),
                'renderer' => Expect::string()->nullable(), 'locale' => Expect::anyOf('en', 'cs')->default('en')])->castTo('array'),
            'authenticator' => Expect::string()->nullable(),
            'oauth' => Expect::anyOf(Expect::string(), Expect::structure([
                'clients' => Expect::structure(['cimd' => Expect::bool(true), 'dcr' => Expect::bool(false),
                    'allowedHosts' => Expect::listOf('string')->default([]), 'metadataFetcher' => Expect::string()->nullable()])->castTo('array'),
            ])->castTo('array'))->default([]),
        ])->castTo('array');
    }

    public function loadConfiguration(): void
    {
        /** @var array{baseUrl: string, name: string, version: string, development: bool, allowedOrigins: list<string>, maxBodyBytes: int, requestsPerMinute: int, scopes: list<string>|string, database: ?string, pdo: ?string, identity: ?string, keys: array{directory: ?string}, consent: array{state: ?string, renderer: ?string, locale: string}, authenticator: ?string, oauth: string|array{clients: array{cimd: bool, dcr: bool, allowedHosts: list<string>, metadataFetcher: ?string}}} $config */
        $config = $this->getConfig();
        $builder = $this->getContainerBuilder();
        $service = fn (string $name): Reference => new Reference($this->prefix($name));
        $external = static fn (string $name): Reference => new Reference(ltrim($name, '@'));
        $builder->addDefinition($this->prefix('config'))->setFactory(Config::class, [
            $config['baseUrl'], $config['name'], $config['version'], $config['development'], $config['allowedOrigins'],
            $config['maxBodyBytes'], $config['requestsPerMinute']]);
        $builder->addDefinition($this->prefix('clock'))->setFactory(SystemClock::class);
        $builder->addDefinition($this->prefix('scopes'))->setFactory(ScopeCatalog::class, [
            is_string($config['scopes']) ? $external($config['scopes']) : $config['scopes']]);
        if ($config['database'] !== null && $config['pdo'] !== null) { throw new \InvalidArgumentException('Configure database or legacy pdo, not both.'); }
        $database = $config['database'] ?? $config['pdo'];
        $reference = $database !== null ? $external($database) : Reference::fromType(
            class_exists(\Nette\Database\Connection::class) ? \Nette\Database\Connection::class : \PDO::class);
        $builder->addDefinition($this->prefix('database'))->setFactory(DatabaseAdapter::class, [$reference]);
        $builder->addDefinition($this->prefix('pdo'))->setFactory([$service('database'), 'getPdo'])->setAutowired(false);
        $builder->addDefinition($this->prefix('store'))->setFactory(Store::class, [$service('pdo'), $service('clock'), new Statement([$service('database'), 'getTransactions'])]);
        $builder->addDefinition($this->prefix('migrator'))->setFactory(SchemaMigrator::class, [$service('pdo')]);
        $builder->addDefinition($this->prefix('tools'))->setFactory(Registry::class);
        $builder->addDefinition($this->prefix('content'))->setFactory(Catalog::class);
        $builder->addDefinition($this->prefix('approvals'))->setFactory(Approvals::class, [$service('store')]);
        $builder->addDefinition($this->prefix('admin'))->setFactory(Administration::class, [$service('store'), $service('scopes')]);
        $builder->addDefinition($this->prefix('tokens'))->setFactory(Tokens::class, [$service('store'), $service('config')]);
        $builder->addDefinition($this->prefix('console'))->setFactory(Context::class, [$service('config'), $service('store'), $service('scopes')]);
        $builder->addDefinition($this->prefix('dispatcher'))->setFactory(Dispatcher::class, [$service('tools'), $service('store'), $service('approvals')]);
        $builder->addDefinition($this->prefix('providers'))->setFactory(ProviderLoader::class, [$service('tools'), $service('content'), [], $service('scopes')]);
        $oauth = is_string($config['oauth']) ? $external($config['oauth']) : null;
        $authenticator = $config['authenticator'] === null ? null : $external($config['authenticator']);
        if ($authenticator === null) {
            if (is_string($config['oauth'])) { throw new \InvalidArgumentException('External OAuth requires an explicit authenticator.'); }
            if ($config['keys']['directory'] === null) { throw new \InvalidArgumentException('Automatic OAuth requires keys.directory.'); }
            $builder->addDefinition($this->prefix('keys'))->setFactory(KeyMaterial::class, [$config['keys']['directory']]);
            $identity = $config['identity'] === null ? $service('identity') : $external($config['identity']);
            if ($config['identity'] === null) {
                $builder->addDefinition($this->prefix('identity'))->setFactory(NetteIdentityProvider::class, [Reference::fromType(\Nette\Security\User::class), $service('scopes')]);
            }
            $state = $config['consent']['state'] === null ? $service('consentState') : $external($config['consent']['state']);
            if ($config['consent']['state'] === null) {
                $builder->addDefinition($this->prefix('consentState'))->setFactory(SessionConsentState::class, [Reference::fromType(\Nette\Http\Session::class), $service('clock')]);
            }
            $renderer = $config['consent']['renderer'] === null ? $service('consentRenderer') : $external($config['consent']['renderer']);
            if ($config['consent']['renderer'] === null) {
                $builder->addDefinition($this->prefix('consentRenderer'))->setFactory(HtmlConsentRenderer::class, [$config['consent']['locale']]);
            }
            $builder->addDefinition($this->prefix('consent'))->setFactory(DefaultConsent::class, [$identity, $state, $renderer, $service('scopes'), $service('store'), $service('config')]);
            $clients = $config['oauth']['clients'];
            $fetcher = $clients['metadataFetcher'] === null ? $service('metadataFetcher') : $external($clients['metadataFetcher']);
            if ($clients['metadataFetcher'] === null) {
                $builder->addDefinition($this->prefix('metadataFetcher'))->setFactory(HttpsMetadataFetcher::class, [$clients['allowedHosts']]);
            }
            $builder->addDefinition($this->prefix('clients'))->setFactory(ClientRegistration::class, [$service('store'), $service('scopes'), $service('admin'), $fetcher, $clients['cimd'], $clients['dcr']]);
            $builder->addDefinition($this->prefix('oauthRepositories'))->setFactory(Repositories::class, [$service('store'), $service('config'), $service('scopes')]);
            $builder->addDefinition($this->prefix('oauth'))->setFactory(Server::class, [
                $service('config'), $service('store'), $service('oauthRepositories'),
                new Statement([$service('keys'), 'privateKey']), new Statement([$service('keys'), 'publicKey']),
                new Statement([$service('keys'), 'encryptionKey']), $service('consent'), $service('clients')]);
            $builder->addDefinition($this->prefix('resource'))->setFactory([$service('oauth'), 'getResourceServer']);
            $builder->addDefinition($this->prefix('bearer'))->setFactory(BearerAuthenticator::class, [$service('resource'), $service('store'), $service('config'), $service('tokens')]);
            $oauth = $service('oauth');
            $authenticator = $service('bearer');
        }
        $builder->addDefinition($this->prefix('http'))->setFactory(Handler::class, [
            $service('config'), $service('store'), $authenticator, $service('tools'),
            $service('dispatcher'), $service('content'), $service('scopes'), $oauth])->addSetup('?->load()', [$service('providers')]);
    }

    public function beforeCompile(): void
    {
        $builder = $this->getContainerBuilder();
        $providers = array_map(static fn (string $name): Reference => new Reference($name), array_keys($builder->findByType(ToolProvider::class)));
        $definition = $builder->getDefinition($this->prefix('providers'));
        if (!$definition instanceof \Nette\DI\Definitions\ServiceDefinition) { throw new \LogicException('Invalid provider service definition.'); }
        $definition->setArgument(2, $providers);
        if (interface_exists(\Nette\Http\IRequest::class) && $builder->findByType(\Nette\Http\IRequest::class) !== []) {
            $builder->addDefinition($this->prefix('application'))->setFactory(HttpApplication::class, [new Reference($this->prefix('http')), Reference::fromType(\Nette\Http\IRequest::class)]);
        }
    }
}
