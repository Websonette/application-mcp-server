# Changelog

## 0.1.0

Initial experimental release.

- Official MCP SDK Streamable HTTP integration, scoped tools/resources/prompts and persistent principal-bound handshake sessions.
- Per-application OAuth authorization code with mandatory S256 PKCE, resource-bound JWT access, refresh rotation and database revocation.
- JSON schema validation, transactional write idempotency, exact human approval tickets and audit/admin services.
- Explicit SQLite/MySQL migrations, request limits, exact origin/host policy and safe handler errors.
- Nette DI integration, standalone loopback demo and explicit project Agent Skill installer.
- PHPUnit regression/process tests, PHPStan level 8, dependency audit and shared Websonette PHP/MySQL CI.

Known scope: pre-registered clients and HTTP only; no production application UI or automatic client registration. See docs/roadmap.md.
