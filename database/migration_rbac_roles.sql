-- ============================================================
-- Migration: RBAC - Role-Based Access Control
-- Adiciona coluna `role` na tabela users
-- Substitui verificação por username === 'Admin'
-- ============================================================
-- Data: 2026-04-22
-- Vulnerabilidade corrigida: #6 (Admin verificado por username)
-- ============================================================

-- 1. Adicionar coluna role (se ainda não existir)
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS `role` ENUM('user','admin','moderator') NOT NULL DEFAULT 'user'
    AFTER `is_verified`;

-- 2. Promover o utilizador 'Admin' a role = 'admin' automaticamente
--    (manter retrocompatibilidade com instalações existentes)
UPDATE users
SET `role` = 'admin'
WHERE LOWER(username) = 'admin'
  AND `role` = 'user'; -- só actualiza se ainda não tiver role

-- 3. Índice para acelerar verificações de role
CREATE INDEX IF NOT EXISTS idx_users_role ON users (`role`);

-- Verificar resultado
SELECT id, username, role FROM users WHERE role != 'user';
