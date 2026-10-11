# Roadmap

## Delivered: 0.2
- Automatic Nette OAuth/consent/key/bearer services, shared Nette Database transaction ownership and standard HTTP bridge.
- One scope catalog with host-owned labels/descriptions and delegation policy.
- Public-client CIMD and optional DCR, registered token authentication methods and native loopback callbacks.
- Explicit non-destructive upgrades from the package's 0.1 schema.
- English/Czech session-bound consent, quotas and expanded regression tests.

## Next candidates
- Refresh grant-family revocation instead of client-wide replay revocation.
- CIMD private_key_jwt/JWKS verification and richer configurable registration policies.
- Replaceable branded consent rendering and application-specific admin integration examples.
- More frameworks/database adapters and transports, based on concrete compatibility requirements.
- Metadata cache-policy refinement, operator cleanup and retention tooling.

Core guarantees do not imply universal client compatibility, automatic domain authorization or transactional rollback of external side effects.
