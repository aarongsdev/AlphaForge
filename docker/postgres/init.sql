-- =============================================================================
-- AlphaForge PostgreSQL Initialization Script
-- =============================================================================
-- This script runs once when the PostgreSQL container is first created.
-- It sets up extensions, performance settings, and the initial schema.

-- Ensure we're operating on the correct database
\c alphaforge_db;

-- =============================================================================
-- EXTENSIONS
-- =============================================================================

-- UUID generation support
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- Trigram-based text search for fast LIKE/ILIKE queries
CREATE EXTENSION IF NOT EXISTS "pg_trgm";

-- GIN index support for arrays, jsonb, and full-text search
CREATE EXTENSION IF NOT EXISTS "btree_gin";

-- Additional index type support for range queries
CREATE EXTENSION IF NOT EXISTS "btree_gist";

-- Cryptographic functions (for hashing, encryption)
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- Additional statistics for the query planner
CREATE EXTENSION IF NOT EXISTS "pg_stat_statements";

-- =============================================================================
-- SCHEMAS
-- =============================================================================

-- Core application schema
CREATE SCHEMA IF NOT EXISTS alphaforge AUTHORIZATION alphaforge;

-- Analytics schema for computed/aggregated data
CREATE SCHEMA IF NOT EXISTS analytics AUTHORIZATION alphaforge;

-- Audit/logging schema
CREATE SCHEMA IF NOT EXISTS audit AUTHORIZATION alphaforge;

-- Search path for the application user
ALTER ROLE alphaforge SET search_path TO alphaforge, public;

-- =============================================================================
-- PERFORMANCE SETTINGS (session-level overrides)
-- =============================================================================

-- Increase work memory for complex queries (sorting, hashing)
ALTER DATABASE alphaforge_db SET work_mem = '16MB';

-- Larger maintenance work mem for VACUUM, CREATE INDEX, etc.
ALTER DATABASE alphaforge_db SET maintenance_work_mem = '128MB';

-- Statistics target for better query plans
ALTER DATABASE alphaforge_db SET default_statistics_target = 200;

-- Enable parallel query execution
ALTER DATABASE alphaforge_db SET max_parallel_workers_per_gather = 4;

-- Improved join strategy
ALTER DATABASE alphaforge_db SET enable_hashjoin = on;
ALTER DATABASE alphaforge_db SET enable_mergejoin = on;
ALTER DATABASE alphaforge_db SET enable_nestloop = on;

-- Row security (will be enforced per-table)
ALTER DATABASE alphaforge_db SET row_security = on;

-- Timezone
ALTER DATABASE alphaforge_db SET timezone = 'UTC';

-- =============================================================================
-- CUSTOM TYPES
-- =============================================================================

-- Asset class enumeration
DO $$ BEGIN
    CREATE TYPE alphaforge.asset_class AS ENUM (
        'stocks',
        'etfs',
        'crypto',
        'commodities',
        'forex',
        'futures',
        'options'
    );
EXCEPTION
    WHEN duplicate_object THEN NULL;
END $$;

-- Signal direction enumeration
DO $$ BEGIN
    CREATE TYPE alphaforge.signal_direction AS ENUM (
        'buy',
        'sell',
        'hold',
        'strong_buy',
        'strong_sell'
    );
EXCEPTION
    WHEN duplicate_object THEN NULL;
END $$;

-- Analysis status enumeration
DO $$ BEGIN
    CREATE TYPE alphaforge.analysis_status AS ENUM (
        'pending',
        'processing',
        'completed',
        'failed',
        'stale'
    );
EXCEPTION
    WHEN duplicate_object THEN NULL;
END $$;

-- Subscription tier enumeration
DO $$ BEGIN
    CREATE TYPE alphaforge.subscription_tier AS ENUM (
        'free',
        'basic',
        'professional',
        'enterprise'
    );
EXCEPTION
    WHEN duplicate_object THEN NULL;
END $$;

-- =============================================================================
-- AUDIT FUNCTIONS
-- =============================================================================

-- Generic audit trigger function
CREATE OR REPLACE FUNCTION audit.log_changes()
RETURNS TRIGGER AS $$
BEGIN
    IF (TG_OP = 'DELETE') THEN
        INSERT INTO audit.audit_log (
            table_name,
            operation,
            old_data,
            changed_by,
            changed_at
        ) VALUES (
            TG_TABLE_NAME,
            'DELETE',
            row_to_json(OLD),
            current_user,
            NOW()
        );
        RETURN OLD;
    ELSIF (TG_OP = 'UPDATE') THEN
        INSERT INTO audit.audit_log (
            table_name,
            operation,
            old_data,
            new_data,
            changed_by,
            changed_at
        ) VALUES (
            TG_TABLE_NAME,
            'UPDATE',
            row_to_json(OLD),
            row_to_json(NEW),
            current_user,
            NOW()
        );
        RETURN NEW;
    ELSIF (TG_OP = 'INSERT') THEN
        INSERT INTO audit.audit_log (
            table_name,
            operation,
            new_data,
            changed_by,
            changed_at
        ) VALUES (
            TG_TABLE_NAME,
            'INSERT',
            row_to_json(NEW),
            current_user,
            NOW()
        );
        RETURN NEW;
    END IF;
END;
$$ LANGUAGE plpgsql SECURITY DEFINER;

-- Updated-at auto-update function
CREATE OR REPLACE FUNCTION alphaforge.update_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- =============================================================================
-- AUDIT TABLE
-- =============================================================================

CREATE TABLE IF NOT EXISTS audit.audit_log (
    id            BIGSERIAL PRIMARY KEY,
    table_name    TEXT NOT NULL,
    operation     TEXT NOT NULL CHECK (operation IN ('INSERT', 'UPDATE', 'DELETE')),
    old_data      JSONB,
    new_data      JSONB,
    changed_by    TEXT NOT NULL DEFAULT current_user,
    changed_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_audit_log_table_name ON audit.audit_log (table_name);
CREATE INDEX IF NOT EXISTS idx_audit_log_changed_at ON audit.audit_log (changed_at DESC);
CREATE INDEX IF NOT EXISTS idx_audit_log_operation ON audit.audit_log (operation);

-- =============================================================================
-- VERIFY SETUP
-- =============================================================================

DO $$
DECLARE
    ext_count INT;
BEGIN
    SELECT COUNT(*) INTO ext_count
    FROM pg_extension
    WHERE extname IN ('uuid-ossp', 'pg_trgm', 'btree_gin', 'btree_gist', 'pgcrypto', 'pg_stat_statements');

    IF ext_count < 6 THEN
        RAISE WARNING 'Some extensions may not have installed correctly. Installed: %', ext_count;
    ELSE
        RAISE NOTICE 'AlphaForge database initialized successfully. All % core extensions installed.', ext_count;
    END IF;
END $$;
