CREATE TABLE IF NOT EXISTS mcp_client_metadata (
    client_id VARCHAR(512) PRIMARY KEY,
    expires_at BIGINT NOT NULL,
    auth_method VARCHAR(32) NOT NULL DEFAULT 'none',
    application_type VARCHAR(8) NOT NULL DEFAULT 'web'
);
