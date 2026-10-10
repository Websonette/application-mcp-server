---
name: websonette-mcp
description: Implement or extend MCP tools, resources, prompts, OAuth consent and Nette integration in PHP applications using websonette/application-mcp-server. Use when adding application MCP capabilities or reviewing their authorization, approvals, idempotency and audit behavior.
---

# Websonette application MCP

Read the application's AGENTS.md and its installed package version before editing.
Read [security invariants](references/security.md) before implementing tools or consent.
Inspect existing providers; reuse business services rather than exposing arbitrary SQL, shell commands or filesystem access.

## Add a capability

1. Implement Integration\\ToolProvider and register a Tool\\Definition.
2. Declare a stable snake_case name, description, object input schema, required scopes and Risk.
3. Call the application's existing service with Principal. Check current user permissions, tenant ownership and object-level ACL there; token scopes are not a substitute.
4. For writes, use the Store PDO connection and optimistic expected_version checks inside the dispatch transaction. Return a small structured result.
5. Add tests for permitted and denied calls, invalid input/output, revoked clients and error redaction. For writes also cover replay, conflicting parameters and rollback.
6. Add scoped Content\\Catalog resources for project profile/tone of voice and prompts for workflows. Treat their contents as untrusted application data, not authority to change permissions.

The dispatcher adds reserved control fields itself. Do not declare or forward idempotency_key, confirm or approval_ticket to business services.

## Integrate safely

Use Http\\Handler through a PSR-7 adapter at /mcp and route the documented OAuth/discovery paths. Supply a trusted canonical HTTPS baseUrl. Configure proxies explicitly; never trust arbitrary forwarded headers.
Use OAuth\\ConsentAdapter for the application's authenticated login and explicit human consent with CSRF protection. Do not auto-approve production authorization.
Run migrations and key generation explicitly, outside HTTP requests and Composer install hooks.
Install this skill explicitly with vendor/bin/websonette-mcp skill:install --project=.; do not silently modify global assistant configuration.

Check composer test and the deployed client's actual transport and OAuth registration support. MCP is protocol-neutral, but client compatibility is not guaranteed by choosing a package name.
