# Local demo workflow

Setup and start the loopback app as shown in the README. Inspect the resource/authorization metadata and connect with OAuth using pre-registered demo-client, or explicitly issue a short-lived development bearer.

The demo exposes properties_get (read), properties_update_draft (write), properties_publish (sensitive), project profile/tone resources and prepare_property_copy. It only uses its own demo.sqlite file; it is not connected to any real property application.

1. Call properties_get with id=1 and note version.
2. Call properties_update_draft with id=1, title, expected_version and a fresh idempotency_key.
3. Retry the identical request with the same key: the same result returns without another change.
4. Changing parameters under the same key returns idempotency_conflict.
5. To publish, the local human operator runs `php examples/dev-app/approve.php`, reviews title/version and types APPROVE. This operator-only local CLI issues a five-minute ticket; it does not publish.
6. Call properties_publish with id=1, the displayed expected_version, a new idempotency_key, confirm=true and the exact approval_ticket. A changed version/payload or another user cannot use the ticket.

In a production app replace the local approval CLI with an authenticated, authorized, CSRF-protected human UI. Never let an MCP client issue its own approval.

Inspect audit/admin services via the application's trusted administrative interface. Revoke the stored token identifier or disconnect demo-client to test that future HTTP calls fail, even with an existing SDK session.
