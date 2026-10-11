# OAuth integration

Production uses League OAuth2 Server with authorization-code + mandatory S256 PKCE and rotating refresh grants. The Nette extension constructs the full chain and supplies session-bound consent UI. IdentityProvider connects the application's existing accounts; legacy/custom ConsentAdapter remains supported.

## Identification and registration

CIMD is enabled by default in automatic OAuth; DCR is opt-in. Pre-registration remains available through trusted administration. Registration never issues a user token or bypasses human consent.

CIMD accepts an exact HTTPS client_id metadata URL up to 512 ASCII bytes. Fetching is limited to port 443, public DNS addresses, verified TLS, 64 KiB and 5 seconds; redirects, proxies, credentials, query/fragment URLs and IP-literal hosts are rejected. DNS is pinned for the connection against rebinding. oauth.clients.allowedHosts can restrict exact lowercase hosts. Failed fetches fail closed, not back to arbitrary redirect acceptance.

The document's client_id must exactly match. Public-client PKCE (none) is supported. The plural token_endpoint_auth_methods_supported field is preferred over the legacy singular field; none must be an available method. private_key_jwt/JWKS verification is not implemented or advertised. Validated metadata is cached for one hour, refreshed only at authorization, not fetched during token exchange. A revoked client is never recreated automatically. Explicit pre-registration is authoritative.

DCR POST /oauth/register accepts a bounded JSON object with redirect_uris, optional client_name, scope, application_type (web/native), grant_types, response_types and token_endpoint_auth_method. Supported methods are none, client_secret_basic and client_secret_post. The RFC default is client_secret_basic; confidential secrets are generated randomly, returned once, stored only hashed and do not expire automatically. Only authorization_code/refresh_token and response_type code are accepted. Unknown scopes and unsupported flows are rejected.

The registration quota is 10 attempts/IP/minute, 100 global/minute and at most 10,000 stored clients. Failed attempts count. Configure edge limits and registration policy for your exposure. Client names and branding are untrusted text; registration alone does not establish trusted client identity. Open DCR is not an OpenAI identity check.

Web callbacks require exact HTTPS URIs. Native applications may use loopback HTTP; only the port varies at authorization, with host/path/query unchanged. Credentials and fragments are rejected. Existing 0.1 manual registrations retain exact loopback callback behavior.

## Discovery and flow

Protected resource metadata points to the application's canonical issuer and /mcp resource. OAuth metadata advertises only enabled registration mechanisms. Missing/invalid bearer credentials receive WWW-Authenticate with resource_metadata.

The client sends random state, resource and S256 code_challenge. The host authenticates the user and displays the client ID and permission descriptions. Consent is an explicit POST protected by expiring single-use browser-session state bound to the exact request, user and displayed scope ceiling. Lost sessions, tampering, replay or changed delegation permissions fail closed. Denial and successful/error authorization redirects include iss; clients must validate it.

A supplied resource must equal the canonical /mcp URI; omitting it still issues only for that fixed resource. The token endpoint enforces the registered authentication method for DCR/CIMD clients. Tokens have RSA signatures, issuer, resource audience, client_id, subject, scopes, expiry and unique IDs. The database stores hashed token IDs, not bearer strings. Every MCP request verifies token/client status as well as signature, audience and expiry. No token passthrough occurs.

## Expiry, revocation and limits

Authorization codes: 5 minutes and single redemption. Access tokens: 15 minutes. Refresh tokens: 30 days, rotated on use. Redemption owns a transaction with MySQL row locks or SQLite conflict retry; a wrong PKCE verifier does not consume a valid code.

Refresh replay conservatively revokes all access/refresh grants for the client, including other users. This is broader than grant-family revocation. Disconnect also revokes authorization codes. Retain replay records appropriately.

Public authorize/token endpoints are limited to 60 requests/IP/minute. The built-in login limits are 8 attempts/IP/15 minutes. Reverse-proxy limits remain advisable. REMOTE_ADDR is used, not unvalidated forwarded headers.

OAuth grants do not replace current object, tenant and user ACLs in handlers. Refresh does not rerun consent. A scope challenge requires explicit step-up authorization; scopes are never silently expanded.

## Host responsibilities and exclusions

The package does not own accounts, provide OIDC login/userinfo, verify CIMD private_key_jwt, support client credentials, or silently install an admin UI. Host applications protect admin/revocation and domain approval endpoints with live ACLs and CSRF.

References: [OpenAI OAuth](https://developers.openai.com/plugins/build/auth), [MCP authorization](https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization), [League OAuth2 Server](https://oauth2.thephpleague.com/), [RFC 7591](https://www.rfc-editor.org/rfc/rfc7591).
