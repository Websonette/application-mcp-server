# Non-negotiable security boundaries

- Read tools are read-only. Risk::Write requires idempotency_key. Risk::Sensitive additionally requires confirm=true and a single-use human approval ticket.
- Human approvals are issued through Approval\\Approvals from an authenticated, authorized, CSRF-protected application UI, never by an unrestricted MCP tool. Show the exact canonical domain parameters and expected_version. Approval is bound to client, user, tool, payload and expiry.
- Reuse the same idempotency key only for the identical operation. A different payload must conflict. A replay may return the stored result without consuming a second approval.
- Atomicity covers only transactional writes through the same PDO connection. For remote APIs, email or queues, write an outbox entry and process it idempotently; do not claim database rollback can undo external effects.
- Enforce current application ACLs in every handler. OAuth scopes represent a delegated ceiling, not a permanently valid user role.
- Keep private keys, encryption keys, bearer credentials and form bodies out of version control and logs. Audit stores identifiers, operation, outcome and correlation ID; cached write results may contain personal data.
- Disconnect clients or revoke token identifiers through Admin\\Administration. Do not retain active access in an SDK session; session data is namespaced by principal and scopes, not an authorization credential.
- HTTPS is mandatory outside explicit loopback development. OAuth uses auth code + S256 PKCE, short-lived resource-bound access tokens and rotating refresh tokens. Pre-register exact callback URLs. No implicit/password grant or open redirect.
- Do not add automatic remote metadata fetching without SSRF protections, explicit trust policy and conformance tests.
- Never bypass JSON schemas or approval checks to make a model call succeed. Return a safe ToolException and explain the required human action.
