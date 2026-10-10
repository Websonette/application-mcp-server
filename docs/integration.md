# Application integration

The core is framework-neutral. Instantiate Config, Store, Registry, Catalog, Approvals and Dispatcher; then construct OAuth Server, BearerAuthenticator and Http Handler as in [the demo app](../examples/dev-app/app.php).

Expose these exact routes through a PSR-7 bridge:

- `/mcp`: Streamable HTTP; authenticated for every request.
- `/.well-known/oauth-protected-resource/mcp` and `/.well-known/oauth-protected-resource`: resource discovery.
- `/.well-known/oauth-authorization-server`: OAuth issuer discovery.
- `/oauth/authorize`: application login and human consent.
- `/oauth/token`: form-encoded authorization-code and refresh exchange.

Config baseUrl is an origin, not a subdirectory. Mounting at a custom path is not supported in 0.1. Do not add a public token issue or approval issue tool.

## Nette DI

```neon
extensions:
    mcp: Websonette\ApplicationMcpServer\Integration\McpExtension

mcp:
    baseUrl: https://app.example
    pdo: @applicationPdo
    authenticator: @mcpBearer
    oauth: @mcpOAuth
    scopes: [project:read, properties:read, properties:write, properties:publish]

services:
    applicationPdo:
        factory: PDO(%mcpDsn%, %mcpUser%, %mcpPassword%)

    consent:
        factory: App\Mcp\ConsentAdapter

    oauthRepositories:
        factory: Websonette\ApplicationMcpServer\OAuth\Repositories
        arguments: [@mcp.store, @mcp.config, [project:read, properties:read, properties:write, properties:publish]]

    mcpOAuth:
        factory: Websonette\ApplicationMcpServer\OAuth\Server
        arguments: [@mcp.config, @mcp.store, @oauthRepositories, %mcpPrivateKey%, %mcpPublicKey%, %mcpEncryptionKey%, @consent]

    mcpResource:
        factory: @mcpOAuth::getResourceServer()

    mcpBearer:
        factory: Websonette\ApplicationMcpServer\OAuth\BearerAuthenticator
        arguments: [@mcpResource, @mcp.store, @mcp.config, @mcp.tokens]

    propertyTools:
        factory: App\Mcp\PropertyTools
```

The application supplies the referenced services, PDO credentials and private key locations. Production secrets should be loaded through the application's secret-management layer; protect compiled DI caches, too.

Services implementing Integration\\ToolProvider are collected and registered once when `mcp.http` is first obtained. The extension does not discover arbitrary application methods or migrate the database. Route a trusted PSR-7 request to `mcp.http->handle()` and emit the returned PSR-7 response using the application's HTTP bridge. No Nette presenter/router is silently installed.

A development-only container can set `development: true`, use a loopback baseUrl and `authenticator: @mcp.tokens` without OAuth. Do not use that authenticator in production.

## Explicit setup

Have `mcp-bootstrap.php` return Console\\Context with the same Config and Store as your application. Protect this operator-only file and do not serve it as a public endpoint.

```sh
php vendor/bin/websonette-mcp status --bootstrap=/private/mcp-bootstrap.php
php vendor/bin/websonette-mcp migrate --bootstrap=/private/mcp-bootstrap.php
php vendor/bin/websonette-mcp keys --directory=/private/application-mcp-keys
php vendor/bin/websonette-mcp client:register --bootstrap=/private/mcp-bootstrap.php --id=desktop-client --name=Desktop --redirect=http://127.0.0.1:9999/callback --scopes=project:read,properties:read
```

Keys are generated with restrictive permissions and never overwritten. The private PEM signs access tokens; public PEM verifies them; encryption.key protects authorization codes and refresh-token payloads. Load the encryption key's contents, not its filename, into OAuth Server.

Back up the database before migrations. Version 1 DDL is repeatable; MySQL DDL cannot be rolled back transactionally.

## Business capabilities

Use scoped resources for `project://profile` and `project://tone-of-voice`, and prompts for workflows. All catalog loaders receive Principal and must enforce current object/tenant permissions if they return private data.

Read tools must not mutate business state. Writes need an idempotency key and optimistic expected_version checks. Sensitive tools need a ticket from Approvals\\issue, called only by the application's authenticated, authorized human approval UI after showing the exact domain parameters. Validate CSRF and the approver's permissions before issuing.

Admin\\Administration is a trusted facade, **not an authenticated controller**. Build your admin UI with current administrator checks and CSRF protection, and pass the real actor ID to mutation methods. Listings return token identifier hashes, never bearer credentials. Do not expose the facade directly through MCP.
