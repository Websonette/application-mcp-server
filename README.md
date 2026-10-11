# Websonette application MCP server

[![CI](https://github.com/Websonette/application-mcp-server/actions/workflows/ci.yml/badge.svg)](https://github.com/Websonette/application-mcp-server/actions/workflows/ci.yml)
[![MySQL](https://github.com/Websonette/application-mcp-server/actions/workflows/mysql.yml/badge.svg)](https://github.com/Websonette/application-mcp-server/actions/workflows/mysql.yml)

A Composer package for adding an authorized MCP endpoint to an existing PHP application. The package supplies OAuth and a default consent UI; the application owns its users, data and business permissions. No central Websonette identity service is required.

PHP 8.3+, SQLite or MySQL/MariaDB, PSR-7 HTTP adapter; optional Nette integration. MIT licensed. The 0.x API is experimental.

## What is included

- Official `mcp/sdk` transport, discovery and protocol handling, serving Streamable HTTP at `/mcp`.
- Both handshake-era sessions persisted across PHP requests and the SDK's modern stateless requests.
- Per-application OAuth authorization code + mandatory S256 PKCE, signed 15-minute access tokens bound to the MCP resource, 30-day rotating refresh tokens and immediate database-backed revocation.
- Scope-filtered tools, resources and prompts, runtime HTTP scope challenges, JSON input/output validation and safe tool errors.
- Transactional write idempotency; sensitive operations require exact, expiring, single-use human approval.
- Audit/admin services, rate and body limits, exact Host/Origin policy, explicit SQLite/MySQL migrations.
- A Nette extension, a runnable local demo and an explicitly installed, provider-neutral development Agent Skill.
- Automatic Nette OAuth wiring, shared database transactions, one scope catalog and session-bound English/Czech consent.
- CIMD client identification and optional DCR, including native loopback callbacks and registration quotas.

This is an integration library, not a ready-made business application. See [architecture](docs/architecture.md) and the [Czech solution summary](docs/solution.cs.md).

## Install

Until the package is submitted to Packagist, use the GitHub VCS repository:

```sh
composer config repositories.websonette-mcp vcs https://github.com/Websonette/application-mcp-server
composer require websonette/application-mcp-server:^0.2
```

The release tag must exist before requiring `^0.2`; for development use `dev-main`.

After registry publication, the VCS configuration is unnecessary. The package never modifies your database, generates keys or installs assistant instructions during Composer installation.

## Local demo

From a checkout with PHP 8.3+ and `pdo_sqlite`:

```sh
composer install
php examples/dev-app/setup.php
MCP_DEMO_PASSWORD='choose-a-unique-local-password' php -S 127.0.0.1:8080 examples/dev-app/router.php
```

The environment assignment above is for a POSIX shell; in PowerShell use `$env:MCP_DEMO_PASSWORD = '...'` before starting PHP.

The endpoint is `http://127.0.0.1:8080/mcp`. A pre-registered public client `demo-client` uses the exact callback `http://127.0.0.1:9999/callback`. Choose matching client configuration or register another callback explicitly. OAuth consent requires the demo password and an explicit approval button.

For a short-lived local test token, run explicitly:

```sh
php bin/websonette-mcp token:issue --bootstrap=examples/dev-app/bootstrap.php --id=demo-client --user=demo-user --scopes=project:read,properties:read,properties:write,properties:publish
```

Keep the resulting bearer credential private. Development tokens are refused outside development mode. Do not deploy the demo router/login in production. [Demo workflow](docs/demo.md) covers safe writes and human approval.

## Integrate

Register tools explicitly with `Tool\\Registry` or an `Integration\\ToolProvider`. For example:

```php
$tools->add(new Definition(
    'properties_update_draft',
    'Update a draft, without publishing it.',
    [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer', 'minimum' => 1],
            'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
            'expected_version' => ['type' => 'integer', 'minimum' => 1],
        ],
        'required' => ['id', 'title', 'expected_version'],
    ],
    ['properties:write'],
    Risk::Write,
    function (array $arguments, Principal $principal) use ($propertyService): array {
        // Check current user, tenant and object permissions here.
        // Use the same PDO connection/transaction as MCP Store.
        return $propertyService->updateDraft($principal, $arguments);
    },
));
```

The dispatcher adds `idempotency_key` for writes and `confirm` + `approval_ticket` for sensitive operations. Applications must not forward these reserved fields to business services.

Follow [integration](docs/integration.md), [OAuth](docs/oauth.md), [security](docs/security.md) and [deployment](docs/deployment.md) before exposing the endpoint publicly.

## Development skill

Install into a selected project only after reviewing the bundled instructions:

```sh
php vendor/bin/websonette-mcp skill:install --project=. --dry-run
php vendor/bin/websonette-mcp skill:install --project=.
```

Default destination: `.agents/skills/websonette-mcp`. `--target=relative/path` supports another agent's project skill directory. No global configuration is touched; differing files and symlinks are rejected. For upgrades, review differences and replace project-owned files deliberately. Skill installation is not a Composer plugin or an automatic runtime permission grant.

## Checks

```sh
composer test
```

This runs strict Composer validation, PHPUnit, PHPStan level 8 and dependency audit. CI uses Websonette's shared workflow on PHP 8.3, 8.4 and 8.5, plus a MySQL 8.4 integration job.

`MCP_TEST_DSN`, `MCP_TEST_USER` and `MCP_TEST_PASSWORD` can run database tests against a **disposable test database only**. Tests delete MCP test records. Never point them at application data.

## Compatibility and scope

MCP is independent of implementation language. Transport and authorization support still matter: a client must support the endpoint's transport, protocol revision and registered OAuth flow. A Node.js, PHP or Python implementation is not automatically compatible with every client.

Version 0.2 provides HTTP integration, pre-registration, public-client CIMD and optional DCR. It does **not** include stdio, legacy HTTP+SSE, CIMD private_key_jwt, an account system, an application-specific admin UI or remote side-effect compensation. Host login and current business ACLs remain application responsibilities. See [integration](docs/integration.md) and [roadmap](docs/roadmap.md).
