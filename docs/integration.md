# Application integration (0.2)

The core remains framework-neutral. Nette supplies a ready-to-wire OAuth and HTTP integration; applications provide identities, scope definitions and business tools.

## Nette

Install nette/database ^3.2, nette/http ^3.3 and nette/security ^3.2 when using their adapters.

```neon
extensions:
    mcp: Websonette\ApplicationMcpServer\Integration\McpExtension

mcp:
    baseUrl: https://app.example
    database: @Nette\Database\Explorer
    keys:
        directory: %rootDir%/config/keys
    scopes: @applicationScopes
    identity: @applicationIdentity
    consent:
        locale: en
    oauth:
        clients:
            cimd: true
            dcr: true

services:
    applicationScopes: App\Mcp\Scopes
    applicationIdentity: App\Mcp\Identity
    businessTools: App\Mcp\Tools
```

database accepts PDO, Nette Database Connection or Explorer. If omitted, the extension uses the unique Connection (or PDO when Nette Database is unavailable). Select a service explicitly when the application has several connections. No second DSN or SharedPdo class is needed.

keys.directory loads private.pem, public.pem and encryption.key at runtime, never during DI compilation. Generate them explicitly with websonette-mcp keys; the extension never generates or replaces them.

scopes accepts one ScopeProvider service or a simple list of IDs. ScopeProvider::getScopes() yields ScopeDefinition(id, label, description). Constants or a backed enum may identify scopes in business tools; there is no second ALL list or OAuth scope configuration. The catalog validates every registered tool/resource/prompt scope. Administration, OAuth, discovery, consent and the console use the same catalog.

identity references IdentityProvider: currentUser, login and allowedScopes. login must authenticate through the host application's existing system and renew its session. allowedScopes returns the user's current delegation ceiling. If identity is omitted, NetteIdentityProvider uses Nette Security User, its authenticator and authorizator: resource "mcp", privilege equal to the scope ID. It does not grant all scopes automatically.

Consent defaults to English (en); Czech (cs) is included. A custom ConsentRenderer or ConsentState may be selected under consent.renderer/state. State must be browser-session-bound, expiring, single-use and bound to the exact client, callback, state, PKCE challenge, resource, subject and displayed permissions. The built-in SessionConsentState handles this with Nette Session. Lost/expired sessions require restarting consent; no unsigned or session-less approval fallback exists.

The extension constructs config, clock, database/pdo, store, migrator, scopes, keys, consent, OAuth repositories/server/resource/bearer, client registration, dispatcher, admin, tokens, console and HTTP services. ToolProvider services are collected automatically. Legacy pdo/authenticator/oauth service references and scope lists from 0.1 remain supported; automatic OAuth requires keys.directory.

## HTTP and deployment

Expose:
- /mcp
- /.well-known/oauth-protected-resource and /.well-known/oauth-protected-resource/mcp
- /.well-known/oauth-authorization-server
- /oauth/authorize and /oauth/token
- /oauth/register when DCR is enabled

When Nette IRequest is available the extension registers Integration\HttpApplication. An existing front controller can call:

```php
$container = App\Bootstrap::boot();
$container->getByType(Websonette\ApplicationMcpServer\Integration\HttpApplication::class)->run();
```

Route the listed paths to that front controller in the web server, or invoke the PSR-7 mcp.http handler from an existing application controller. Routes are not silently inserted into the host's router. The bridge uses Nette's trusted HTTP request factory; configure trusted proxies there. Never blindly rewrite Host/scheme or trust client-supplied forwarded headers.

baseUrl is the canonical HTTPS origin (development allows loopback HTTP), not a subdirectory. allowedOrigins are explicit browser origins, not client identities or OAuth callbacks; leave them empty unless cross-origin browser requests are needed.

## Explicit setup and upgrades

Have a protected CLI bootstrap return the registered Console\Context. Composer installation and HTTP requests never migrate the database.

```sh
php vendor/bin/websonette-mcp keys --directory=/private/application-mcp-keys
php vendor/bin/websonette-mcp status --bootstrap=/private/mcp-bootstrap.php
php vendor/bin/websonette-mcp migrate --bootstrap=/private/mcp-bootstrap.php
```

Back up the database before deployment. Migration 2 expands client IDs for metadata URLs and adds client metadata/authentication options, preserving package-0.1 clients, tokens and audit. Incompatible legacy tables are rejected before changes; use a separately reviewed application migration, never automatic DROP TABLE. MySQL DDL is not transactionally reversible. Automatic-OAuth endpoints return schema_upgrade_required until migration 2 is installed.

## Business guarantees

Write handlers must use the same connection. With Nette, Store owns the outer Nette transaction and ordinary nested Nette transaction() callbacks participate in it. Do not invoke dispatch inside an already active application transaction or manually commit/rollback the dispatch-owned transaction. External writes, files, e-mails and queues need application outbox/compensation and their own idempotence.

Current user, tenant and object permissions still belong in business handlers. OAuth consent is delegation, not a replacement for live ACLs. Refresh does not rerun login/consent.

Sensitive tools need exact single-use tickets from Approvals::issue. The application's authenticated human UI must display domain parameters/version, enforce CSRF and approver ACLs before issuing; a model's confirm flag is not human authorization.

Administration is a trusted facade, not an authenticated HTTP controller. Keep its host-admin access checks and CSRF protections, and pass the real actor ID.
