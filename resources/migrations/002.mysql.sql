ALTER TABLE mcp_clients MODIFY id VARCHAR(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE mcp_access_tokens MODIFY client_id VARCHAR(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE mcp_authorization_codes MODIFY client_id VARCHAR(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE mcp_audit_log MODIFY client_id VARCHAR(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
CREATE TABLE IF NOT EXISTS mcp_client_metadata (
    client_id VARCHAR(512) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    expires_at BIGINT NOT NULL,
    auth_method VARCHAR(32) NOT NULL DEFAULT 'none',
    application_type VARCHAR(8) NOT NULL DEFAULT 'web'
) ENGINE=InnoDB;
