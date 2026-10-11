# Changelog

## 0.2.1

- CLI status and migrate report the current schema version rather than the old version-1 label.

## 0.2.0

- Automatic Nette OAuth, key material, identity/consent, bearer and console service wiring; legacy explicit wiring remains supported.
- Single ScopeProvider/ScopeCatalog for OAuth, discovery, consent, administration and capability validation.
- Shared Nette Database connection with Nette-managed nested transactions and a standard HTTP bridge.
- Default English/Czech consent pages, session-bound single-use forms, current delegation policy and login/public OAuth limits.
- CIMD with public-client PKCE, bounded public-HTTPS fetching, DNS pinning, metadata cache and durable revocation.
- Optional RFC 7591 registration with quotas, hashed confidential secrets and declared token endpoint authentication.
- Native loopback redirect port matching without host/path/query relaxation.
- Explicit non-destructive version-2 migrations and rejection of incompatible legacy tables.
- Additional registration, PKCE, consent, migration and Nette transaction regression coverage.

## 0.1.0

Initial experimental release.

- Official MCP SDK Streamable HTTP integration, scoped tools/resources/prompts and persistent principal-bound handshake sessions.
- Per-application OAuth authorization code with mandatory S256 PKCE, resource-bound JWT access, refresh rotation and database revocation.
- JSON schema validation, transactional write idempotency, exact human approval tickets and audit/admin services.
- Explicit SQLite/MySQL migrations, request limits, exact origin/host policy and safe handler errors.
- Nette DI integration, standalone loopback demo and explicit project Agent Skill installer.
- PHPUnit regression/process tests, PHPStan level 8, dependency audit and shared Websonette PHP/MySQL CI.

0.1 scope: pre-registered clients and HTTP only; no default consent UI or automatic client registration.
