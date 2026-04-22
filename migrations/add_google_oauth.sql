-- ============================================================
-- Migration: Adicionar suporte a Google OAuth
-- Executar UMA VEZ na base de dados mytube_db
-- ============================================================

-- 1. Adicionar coluna google_id à tabela users
ALTER TABLE users
    ADD COLUMN google_id VARCHAR(50) NULL DEFAULT NULL
        COMMENT 'Google sub (ID único do utilizador Google)'
    AFTER password;

-- 2. Índice único para evitar duplicados e acelerar lookups
ALTER TABLE users
    ADD UNIQUE INDEX idx_google_id (google_id);

-- ============================================================
-- Verificar resultado:
--   SHOW COLUMNS FROM users LIKE 'google_id';
-- ============================================================
