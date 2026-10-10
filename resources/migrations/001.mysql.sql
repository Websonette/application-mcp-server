CREATE TABLE IF NOT EXISTS mcp_schema_migrations (version INTEGER PRIMARY KEY) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS mcp_sessions (
    session_id VARCHAR(36) PRIMARY KEY,
    principal_key VARCHAR(64) NOT NULL,
    payload MEDIUMTEXT NOT NULL,
    expires_at BIGINT NOT NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS mcp_clients (
    id VARCHAR(128) PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    redirect_uris TEXT NOT NULL,
    scopes TEXT NOT NULL,
    secret_hash VARCHAR(255),
    confidential INTEGER NOT NULL DEFAULT 0,
    revoked INTEGER NOT NULL DEFAULT 0,
    created_at BIGINT NOT NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS mcp_access_tokens (
    id VARCHAR(128) PRIMARY KEY,
    client_id VARCHAR(128) NOT NULL,
    user_id VARCHAR(128),
    scopes TEXT NOT NULL,
    expires_at BIGINT NOT NULL,
    revoked INTEGER NOT NULL DEFAULT 0
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS mcp_refresh_tokens (
    id VARCHAR(128) PRIMARY KEY,
    access_token_id VARCHAR(128) NOT NULL,
    expires_at BIGINT NOT NULL,
    revoked INTEGER NOT NULL DEFAULT 0
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS mcp_authorization_codes (
    id VARCHAR(128) PRIMARY KEY,
    client_id VARCHAR(128) NOT NULL,
    expires_at BIGINT NOT NULL,
    revoked INTEGER NOT NULL DEFAULT 0
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS mcp_approvals (
    ticket_hash CHAR(64) PRIMARY KEY,
    principal_key CHAR(64) NOT NULL,
    tool VARCHAR(64) NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    approver_id VARCHAR(128) NOT NULL,
    expires_at BIGINT NOT NULL,
    consumed INTEGER NOT NULL DEFAULT 0
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS mcp_idempotency (
    key_hash CHAR(64) PRIMARY KEY,
    payload_hash CHAR(64) NOT NULL,
    result_json MEDIUMTEXT NOT NULL,
    created_at BIGINT NOT NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS mcp_audit_log (
    id CHAR(32) PRIMARY KEY,
    occurred_at BIGINT NOT NULL,
    client_id VARCHAR(128) NOT NULL,
    user_id VARCHAR(128),
    operation VARCHAR(128) NOT NULL,
    outcome VARCHAR(64) NOT NULL,
    request_id VARCHAR(128) NOT NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS mcp_rate_limits (
    principal_key CHAR(64) PRIMARY KEY,
    window_id BIGINT NOT NULL,
    hits INTEGER NOT NULL
) ENGINE=InnoDB;
