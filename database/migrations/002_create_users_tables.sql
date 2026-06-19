-- ============================================================
-- Migration: 002_create_users_tables.sql
-- Description: Users, roles, sessions, audit, and API key tables
-- Platform: PostgreSQL 15+
-- ============================================================

-- ------------------------------------------------------------
-- Table: users
-- Core user account information
-- ------------------------------------------------------------
CREATE TABLE users (
    id                      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    email                   VARCHAR(255) NOT NULL,
    username                VARCHAR(100),
    password_hash           VARCHAR(255) NOT NULL,           -- Argon2id hash
    first_name              VARCHAR(100),
    last_name               VARCHAR(100),
    phone                   VARCHAR(30),
    avatar_url              TEXT,
    email_verified_at       TIMESTAMPTZ,
    is_active               BOOLEAN NOT NULL DEFAULT TRUE,
    failed_login_attempts   INTEGER NOT NULL DEFAULT 0,
    locked_until            TIMESTAMPTZ,
    last_login_at           TIMESTAMPTZ,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT users_email_unique       UNIQUE (email),
    CONSTRAINT users_username_unique    UNIQUE (username),
    CONSTRAINT users_email_format       CHECK (email ~* '^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$'),
    CONSTRAINT users_failed_attempts_nn CHECK (failed_login_attempts >= 0)
);

COMMENT ON TABLE  users                         IS 'Core user accounts for AlphaForge platform';
COMMENT ON COLUMN users.password_hash           IS 'Argon2id hashed password';
COMMENT ON COLUMN users.failed_login_attempts   IS 'Consecutive failed login attempts; resets on success';
COMMENT ON COLUMN users.locked_until            IS 'Account locked until this timestamp after too many failures';

CREATE INDEX idx_users_email        ON users (email);
CREATE INDEX idx_users_username     ON users (username);
CREATE INDEX idx_users_is_active    ON users (is_active) WHERE is_active = TRUE;
CREATE INDEX idx_users_created_at   ON users (created_at DESC);

-- ------------------------------------------------------------
-- Table: roles
-- Defines platform roles with jsonb permissions
-- ------------------------------------------------------------
CREATE TABLE roles (
    id          UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name        VARCHAR(50) NOT NULL,
    description TEXT,
    permissions JSONB NOT NULL DEFAULT '{}',
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT roles_name_unique    UNIQUE (name),
    CONSTRAINT roles_name_values    CHECK (name IN ('admin', 'analyst', 'trader', 'viewer'))
);

COMMENT ON TABLE  roles             IS 'Platform roles: admin, analyst, trader, viewer';
COMMENT ON COLUMN roles.permissions IS 'Structured jsonb object defining granular permissions';

CREATE INDEX idx_roles_name         ON roles (name);
CREATE INDEX idx_roles_permissions  ON roles USING GIN (permissions);

-- ------------------------------------------------------------
-- Table: user_roles
-- Many-to-many mapping between users and roles
-- ------------------------------------------------------------
CREATE TABLE user_roles (
    user_id     UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    role_id     UUID NOT NULL REFERENCES roles (id) ON DELETE CASCADE,
    assigned_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    assigned_by UUID REFERENCES users (id) ON DELETE SET NULL,

    CONSTRAINT user_roles_pk PRIMARY KEY (user_id, role_id)
);

COMMENT ON TABLE user_roles IS 'Assigns roles to users; tracks who performed the assignment';

CREATE INDEX idx_user_roles_user_id     ON user_roles (user_id);
CREATE INDEX idx_user_roles_role_id     ON user_roles (role_id);
CREATE INDEX idx_user_roles_assigned_by ON user_roles (assigned_by);

-- ------------------------------------------------------------
-- Table: password_reset_tokens
-- Secure password reset flow tokens
-- ------------------------------------------------------------
CREATE TABLE password_reset_tokens (
    id          UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id     UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    token       VARCHAR(255) NOT NULL,               -- SHA-256 hash of the actual token
    expires_at  TIMESTAMPTZ NOT NULL,
    used_at     TIMESTAMPTZ,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT prt_token_unique CHECK (char_length(token) > 0)
);

COMMENT ON TABLE  password_reset_tokens        IS 'Hashed single-use password reset tokens';
COMMENT ON COLUMN password_reset_tokens.token  IS 'SHA-256 hash of the plaintext reset token sent to user';

CREATE INDEX idx_prt_user_id    ON password_reset_tokens (user_id);
CREATE INDEX idx_prt_expires_at ON password_reset_tokens (expires_at);
CREATE INDEX idx_prt_token      ON password_reset_tokens (token);

-- ------------------------------------------------------------
-- Table: user_sessions
-- Active authenticated sessions
-- ------------------------------------------------------------
CREATE TABLE user_sessions (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id             UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    token_hash          VARCHAR(255) NOT NULL,
    ip_address          INET,
    user_agent          TEXT,
    device_fingerprint  VARCHAR(255),
    is_active           BOOLEAN NOT NULL DEFAULT TRUE,
    expires_at          TIMESTAMPTZ NOT NULL,
    last_activity       TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT user_sessions_token_hash_unique UNIQUE (token_hash)
);

COMMENT ON TABLE  user_sessions                    IS 'Authenticated user sessions; supports concurrent device login';
COMMENT ON COLUMN user_sessions.token_hash         IS 'SHA-256 hash of the session bearer token';
COMMENT ON COLUMN user_sessions.device_fingerprint IS 'Browser/device fingerprint for anomaly detection';

CREATE INDEX idx_user_sessions_user_id       ON user_sessions (user_id);
CREATE INDEX idx_user_sessions_token_hash    ON user_sessions (token_hash);
CREATE INDEX idx_user_sessions_active        ON user_sessions (user_id, is_active) WHERE is_active = TRUE;
CREATE INDEX idx_user_sessions_expires_at    ON user_sessions (expires_at);

-- ------------------------------------------------------------
-- Table: audit_logs
-- Immutable audit trail of platform actions
-- ------------------------------------------------------------
CREATE TABLE audit_logs (
    id          UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id     UUID REFERENCES users (id) ON DELETE SET NULL,  -- nullable: system actions
    action      VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100),
    entity_id   UUID,
    old_values  JSONB,
    new_values  JSONB,
    ip_address  INET,
    user_agent  TEXT,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE  audit_logs            IS 'Immutable audit log; records all state-changing platform actions';
COMMENT ON COLUMN audit_logs.user_id    IS 'NULL for system-initiated actions';
COMMENT ON COLUMN audit_logs.action     IS 'Verb describing the action, e.g. user.login, trade.create';
COMMENT ON COLUMN audit_logs.old_values IS 'JSON snapshot of entity state before the change';
COMMENT ON COLUMN audit_logs.new_values IS 'JSON snapshot of entity state after the change';

CREATE INDEX idx_audit_logs_user_id     ON audit_logs (user_id);
CREATE INDEX idx_audit_logs_action      ON audit_logs (action);
CREATE INDEX idx_audit_logs_entity      ON audit_logs (entity_type, entity_id);
CREATE INDEX idx_audit_logs_created_at  ON audit_logs (created_at DESC);
CREATE INDEX idx_audit_logs_old_values  ON audit_logs USING GIN (old_values);
CREATE INDEX idx_audit_logs_new_values  ON audit_logs USING GIN (new_values);

-- ------------------------------------------------------------
-- Table: api_keys
-- Programmatic access keys with per-key permissions and rate limits
-- ------------------------------------------------------------
CREATE TABLE api_keys (
    id                      UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    user_id                 UUID NOT NULL REFERENCES users (id) ON DELETE CASCADE,
    name                    VARCHAR(100) NOT NULL,
    key_hash                VARCHAR(255) NOT NULL,
    permissions             JSONB NOT NULL DEFAULT '[]',
    rate_limit_per_minute   INTEGER NOT NULL DEFAULT 60,
    last_used_at            TIMESTAMPTZ,
    expires_at              TIMESTAMPTZ,
    is_active               BOOLEAN NOT NULL DEFAULT TRUE,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT api_keys_key_hash_unique         UNIQUE (key_hash),
    CONSTRAINT api_keys_rate_limit_positive     CHECK (rate_limit_per_minute > 0),
    CONSTRAINT api_keys_name_not_empty          CHECK (char_length(TRIM(name)) > 0)
);

COMMENT ON TABLE  api_keys                      IS 'API keys for programmatic platform access';
COMMENT ON COLUMN api_keys.key_hash             IS 'SHA-256 hash of the plaintext API key';
COMMENT ON COLUMN api_keys.permissions          IS 'JSON array of allowed permission strings, e.g. ["signals:read","portfolio:write"]';
COMMENT ON COLUMN api_keys.rate_limit_per_minute IS 'Maximum API calls allowed per minute for this key';

CREATE INDEX idx_api_keys_user_id   ON api_keys (user_id);
CREATE INDEX idx_api_keys_key_hash  ON api_keys (key_hash);
CREATE INDEX idx_api_keys_active    ON api_keys (user_id, is_active) WHERE is_active = TRUE;
CREATE INDEX idx_api_keys_perms     ON api_keys USING GIN (permissions);
