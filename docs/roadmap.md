# Version 0.1 scope and follow-up work

Implemented: explicit Composer library, official SDK HTTP transport, per-application auth-code PKCE/refresh OAuth, pre-registered clients, scopes, safe transactional writes, approvals, audit/admin facade, persistent sessions, SQLite/MySQL migration, Nette DI, standalone demo, explicit Agent Skill installer and automated tests.

Before a production rollout in a specific application:

- Implement its actual business-service providers and object/tenant ACL tests.
- Implement production login/consent, approval and admin screens.
- Review operational policies, log redaction, key storage, retention and client configuration.
- Run a deployment security/conformance review against intended MCP clients.

Possible package enhancements, requiring separate design/tests:

- Grant-family replay revocation instead of the conservative client-wide response.
- Trusted CIMD or DCR registration with SSRF/trust controls.
- Production key rollover, additional storage adapters and optional cleanup tooling.
- Optional stdio integration for local workflows; HTTP OAuth is not an stdio authentication mechanism.
- Outbox integration helpers and concrete application adapters.

Packagist publication is an organization account operation and separate from creating a GitHub repository/release. Until registered, install through the VCS repository. No unspecified future capability is advertised as implemented.
