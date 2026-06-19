-- ============================================================
-- Migration: 001_create_extensions.sql
-- Description: Enable required PostgreSQL extensions
-- Platform: PostgreSQL 15+
-- ============================================================

-- Enable UUID generation support
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- Enable trigram-based text similarity and GIN/GiST index support for LIKE/ILIKE queries
CREATE EXTENSION IF NOT EXISTS "pg_trgm";

-- Enable btree operator classes usable in GIN indexes (for multi-column GIN indexes)
CREATE EXTENSION IF NOT EXISTS "btree_gin";

-- Enable query statistics collection for performance monitoring
CREATE EXTENSION IF NOT EXISTS "pg_stat_statements";
