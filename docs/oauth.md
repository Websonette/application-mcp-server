# OAuth integration

Production uses the maintained League OAuth2 server, restricted to authorization code and refresh-token grants. The application implements OAuth\\ConsentAdapter; the package does not own accounts or auto-approve humans.

## Flow and discovery

1. A missing/invalid bearer receives HTTP 401 with WWW-Authenticate pointing to resource metadata.
2. The client discovers the application issuer and its authorization/token endpoints.
3. An administrator pre-registers the client ID, exact callback(s) and allowed scopes.
4. The client starts authorization with response_type=code, scope, random state, an S256 code_challenge and resource equal to the canonical /mcp URL.
5. The adapter authenticates the human, shows the verified client and requested permissions, checks current user ACLs, validates CSRF on approval, and returns OAuth\\User. It can reduce the AuthorizationRequest scopes before returning the user. A login/consent page or denial is returned as a PSR response.
6. The client validates callback state and issuer, then exchanges the code with code_verifier and the same redirect_uri/resource.
7. The client sends the access token only in the Authorization: Bearer header.

The authorization redirect includes the issuer parameter. Callback fragments, credentials and non-loopback HTTP URLs are rejected at registration. S256 is mandatory for public **and** confidential clients; missing challenges and plain PKCE are rejected.

Access tokens are signed RSA JWTs, with issuer, canonical resource audience, client_id, subject, scopes, expiry and a unique token ID. The DB stores only hashed token IDs, not bearer strings. Signature alone is insufficient: each request also verifies current token/client status. No token passthrough to downstream services is supported.

For compatibility, an omitted resource parameter still issues only for this server's fixed resource. A supplied different resource is rejected.

## Expiry, rotation and revocation

Authorization code: 5 minutes, single redemption. Access token: 15 minutes. Refresh token: 30 days and replaced on each refresh. The server owns a transaction during redemption; MySQL rows are locked through validation/issuance and SQLite conflicting writers must retry. An invalid verifier does not consume a valid authorization code.

On detected refresh replay, 0.1 conservatively revokes **all access and refresh grants for the client**, including other users of that client. This is broader than grant-family revocation and can interrupt legitimate sessions; reconnect through fresh consent. The replay outcome is audited. Do not remove replay records prematurely.

Admin client disconnect also revokes authorization codes. Individual token revocation invalidates associated refresh access. Current object/tenant/user permissions must still be checked in application tool handlers; a refresh grant does not itself re-run the consent adapter.

A known restricted tool/resource/prompt returns HTTP 403 with error=insufficient_scope and its required scope set. Lists include only delegated capabilities. A client must explicitly request new permissions through authorization; the server never silently expands scopes.

## Not included

Automatic dynamic client registration, CIMD fetching, JWKS publication, OIDC login, central IdP, token introspection/revocation HTTP endpoints, client-credentials grant and production consent UI are not implemented. They are not advertised in metadata. Use trusted admin services for revocation.

Authorization endpoint abuse, login brute-force and unauthenticated token requests need reverse-proxy/application rate limits. The built-in minute limiter protects authenticated MCP requests, not the public login flow.

References: [MCP authorization](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization), [League OAuth2 server](https://oauth2.thephpleague.com/), [RFC 8707 resource indicators](https://www.rfc-editor.org/rfc/rfc8707).
