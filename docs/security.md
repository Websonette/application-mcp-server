# Security model

## Required application controls

- Authenticate and explicitly authorize humans in OAuth consent and sensitive approval UI; validate CSRF and current user permissions. Do not use the test consent adapter in a real application.
- Authorize current user, tenant and object on every tool/resource/prompt call. Scope checks are a delegated ceiling only.
- Use HTTPS, exact callbacks/origins, trusted proxy normalization, protected key storage, secure admin cookies and application-level request/response/error handling.
- Register clients through an authorized administrator; validate requested scope ceilings. Use long random confidential-client secrets.
- Use the same PDO/InnoDB transaction for business writes and MCP control records. Do not perform remote side effects inside a handler; use an outbox.
- Apply login/OAuth unauthenticated rate limits at the edge. The built-in MCP limiter counts authenticated requests in UTC-minute windows.
- Monitor and retain audit records, but avoid logging Authorization headers, OAuth forms, approval tickets, arbitrary handler errors or full MCP bodies.

## Built-in boundaries

Production Config rejects non-HTTPS base origins. Development explicitly permits loopback HTTP only. Exact request Host and URI authority/scheme are compared with Config. A present Origin must match the configured origin or an explicit allowlist; wildcards and remote plain HTTP origins are rejected. X-Forwarded-* headers are not trusted by this package.

Request bodies are bounded while reading, including chunked/partial streams. Configure lower edge limits and HTTP timeouts as well; an application bridge that buffers the entire body before invoking Handler has its own memory responsibility.

Bearer identity is validated before registry construction. SDK session state cannot bypass current token/client checks. Session IDs are namespaced by client, user and scope set. Input and optional output schemas are validated; unexpected top-level fields and reserved argument collisions are rejected.

Write idempotency is scoped to client/user/tool/key. It binds the canonical domain arguments, sorts object keys, preserves list ordering and distinguishes integers from floats. A new payload under the same key conflicts. A sensitive approval additionally binds the exact user, client, tool, domain payload and expiry, and is consumed in the write transaction.

A failed transactional write rolls back the reservation, approval consumption and business change, and adds a failure audit outside the transaction. It cannot undo external API calls.

## Data and operations

Schema version 1 is installed explicitly. PHP requires pdo_sqlite or pdo_mysql depending on deployment; the package does not create application business tables. Use one database/Store per application/tenant boundary unless every provider implements explicit tenant separation.

Audit contains client/user identifiers and can itself be personal data. Idempotency contains successful results. Restrict admin/DB permissions and define retention with privacy and retry requirements. Transient sessions, rate windows and expired approvals can be pruned by application maintenance; keep authorization/refresh revocation state for at least its credential lifetime. Do not prune idempotency results until business retry semantics permit a key to become reusable.

Compromise of signing/encryption keys requires operator-led rotation, revocation of grants and coordination with active deployments. Automated key rollover is not included in 0.1.

Treat prompts, resources, uploaded documents and model outputs as untrusted data. They cannot authorize an operation or substitute for human confirmation. The skill is guidance, not a security boundary.

Report vulnerabilities using [SECURITY.md](../SECURITY.md); include reproduction steps without real tokens, keys or personal data.
