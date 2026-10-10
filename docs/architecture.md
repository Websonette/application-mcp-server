# Architecture

Each application runs its own MCP resource and OAuth issuer at its canonical origin. The package has no dependency on a central Websonette authentication service.

| Layer | Responsibility |
| --- | --- |
| Official MCP SDK | Protocol envelopes, capability listing, HTTP transport, handshake/stateless dispatch |
| Http\\Handler | Discovery, bearer authentication, exact origin/host policy, bounded body, rate limit, scope challenges |
| OAuth\\Server + League OAuth2 | Authorization-code/refresh flows, PKCE, signed resource-bound tokens |
| Tool\\Dispatcher | Client/scopes, schemas, reserved control fields, transaction, idempotency, approvals, audit |
| Application provider/services | Current user/tenant/object ACLs, business validation, optimistic version checks |
| Store + explicit migrations | Token identifiers, clients, sessions, approvals, idempotency results, audit |
| Admin\\Administration | Trusted admin facade for client registration/listing, token revocation, disconnect and activity |
| Content\\Catalog | Scoped project resources and workflow prompts |
| Agent Skill | Development guidance only; never runtime authorization |

## Request lifecycle

1. Reject an unexpected canonical Host, scheme/authority or Origin before authentication.
2. Read at most the configured body limit. Discovery is public; OAuth uses the application's consent adapter.
3. Validate bearer signature/issuer/audience and current token/client state. Rate-limit the established principal.
4. Build an SDK registry filtered by scopes; direct calls to known restricted capabilities receive an HTTP 403 scope challenge.
5. Validate tool input independently of SDK reflection. Read tools run without a write transaction.
6. For writes: reserve the principal/tool/idempotency key, compare the canonical domain payload, consume exact approval if sensitive, execute business logic, validate output, store the result and audit in the same transaction.
7. Return structured MCP content or a safe error and a server-generated correlation ID.

The MCP session is not an authorization grant. Handshake session storage is namespaced by client, user and scope set, expires after one hour of inactivity and is reauthenticated for every request. Modern requests are dispatched using the SDK's supported revision rules.

## Data ownership and atomicity

Business writes and MCP control records must use the **same transactional PDO connection**, outside an existing application transaction. Handlers must not commit, roll back or open their own transaction. MySQL business tables must use InnoDB.

The reservation is inserted before the handler, preventing concurrent duplicated writes. A conflicting snapshot/deadlock can produce a transient internal error; retry with the same key. The test suite launches three PHP processes against the same operation and verifies one effect.

An external API call is not rolled back by a database transaction. Integrate remote publication, mail or queues through a transactionally inserted outbox and an idempotent worker. Distributed transactions and outbox workers are application responsibilities, not built-in promises.

## Error and audit policy

Tools expose ToolException codes and deliberately safe messages, not stack traces or raw exceptions. Resources/prompts sanitize handler failures. Audit records identifiers, operation, outcome and correlation ID, never raw arguments, token strings or arbitrary exception details. Human approval records additionally store approver identity and a payload hash.

Successful write results are cached for idempotency and can contain personal data. Limit output content, restrict database/admin access, encrypt backups and establish a documented retry/retention policy. Deleting idempotency entries can allow the same old key to execute again.

## Registration policy

Clients are pre-registered by an authorized application administrator, with exact HTTPS or loopback callback URLs and a scope ceiling. Public clients require PKCE; confidential clients additionally use a hashed secret. No arbitrary URL is fetched during registration. CIMD/DCR needs a separate trust/SSRF design before implementation.
