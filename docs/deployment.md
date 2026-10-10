# Deployment checklist

1. Install reviewed dependencies; keep PHP 8.3+ and Composer packages patched.
2. Set one canonical HTTPS origin per application, with a certificate trusted by intended clients.
3. Back up the DB and run the explicit versioned migration. Use InnoDB for MySQL business tables; configure transaction isolation/lock timeouts and retry transient errors with the same idempotency key.
4. Generate private RSA and encryption material outside the public directory. Grant only the PHP worker account read access. Never commit or log keys.
5. Implement and review current application ACLs, OAuth login/consent and human approval UI. Secure login sessions and CSRF; pre-register verified callback URLs and least-privilege scopes.
6. Bridge only the documented routes to Http Handler. The canonical request URI must be restored by a proxy adapter that trusts **only known proxies**; do not blindly copy X-Forwarded-Host/Proto from the internet.
7. Preserve Authorization, MCP-Protocol-Version, Mcp-Session-Id and modern Mcp-Method/Mcp-Name headers. Configure body/time limits, no shared response caching, no request-body/credential logging and edge quotas for OAuth/login.
8. Use shared persistent database-backed sessions/control records across PHP workers. Do not rely on a process-local SDK session store in PHP-FPM.
9. Establish audit monitoring, explicit retention, backups and key rotation/revocation procedures.
10. Test each intended client: HTTP transport, actual protocol revision, OAuth pre-registration/callback behavior, scope escalation, write retry and revoked access.

The local demo is a test fixture, not a production deployment. Client-specific connection configuration and automatic-registration-only clients require additional integration; no universal client compatibility is claimed.
