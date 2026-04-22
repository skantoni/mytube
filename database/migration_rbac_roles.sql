-- ============================================================
-- Migration: RBAC - Role-Based Access Control
-- Adiciona coluna `role` na tabela users
-- Substitui verificação por username === 'Admin'
-- ============================================================
-- Data: 2026-04-22
-- Vulnerabilidade corrigida: #6 (Admin verificado por username)
-- ============================================================

-- 1. Adicionar coluna role (compatível com MySQL 5.7+)
SET @dbname = DATABASE();
SET @sql_add_col = (
    SELECT IF(
        (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = @dbname
           AND TABLE_NAME = 'users'
           AND COLUMN_NAME = 'role') > 0,
        'SELECT ''Column role already exists'' AS info',
        'ALTER TABLE users ADD COLUMN `role` ENUM(''user'',''admin'',''moderator'') NOT NULL DEFAULT ''user'' AFTER `is_verified`'
    )
);
PREPARE stmt_add_col FROM @sql_add_col;
EXECUTE stmt_add_col;
DEALLOCATE PREPARE stmt_add_col;

-- 2. Promover o utilizador 'Admin' a role = 'admin' automaticamente
--    (manter retrocompatibilidade com instalações existentes)
UPDATE users
SET `role` = 'admin'
WHERE LOWER(username) = 'admin'
  AND `role` = 'user'; -- só actualiza se ainda não tiver role

-- 3. Índice para acelerar verificações de role (compatível com MySQL 5.7+)
SET @sql_add_idx = (
    SELECT IF(
        (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = @dbname
           AND TABLE_NAME = 'users'
           AND INDEX_NAME = 'idx_users_role') > 0,
        'SELECT ''Index idx_users_role already exists'' AS info',
        'CREATE INDEX idx_users_role ON users (`role`)'
    )
);
PREPARE stmt_add_idx FROM @sql_add_idx;
EXECUTE stmt_add_idx;
DEALLOCATE PREPARE stmt_add_idx;

-- Verificar resultado
SELECT id, username, role FROM users WHERE role != 'user';
