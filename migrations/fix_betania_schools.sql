-- ============================================================
-- MIGRAÇÃO: Unificar escolas Betânia
-- IDs a manter:
--   35 = Complexo Escolar Privado Betânia Zango - I
--   36 = Complexo Escolar Privado Betânia Zango - III
-- IDs a remover:
--   45 = Colégio Betânia           → migrar users para 35
--   30 = Complexo Escolar Betânia Zango-I   → migrar users para 35
--   29 = Complexo Escolar Betânia Zango-III → migrar users para 36
-- ============================================================

-- PASSO 1: Ver quantos users serão migrados
SELECT school_id, COUNT(*) AS total_users
FROM users
WHERE school_id IN (29, 30, 45)
GROUP BY school_id;

-- PASSO 2: Migrar users
UPDATE users SET school_id = 35 WHERE school_id = 45;
UPDATE users SET school_id = 35 WHERE school_id = 30;
UPDATE users SET school_id = 36 WHERE school_id = 29;

-- PASSO 3: Remover escolas duplicadas
DELETE FROM schools WHERE id IN (45, 30, 29);

-- PASSO 4: Confirmar resultado final
SELECT s.id, s.name, COUNT(u.id) AS total_users
FROM schools s
LEFT JOIN users u ON u.school_id = s.id
WHERE s.id IN (35, 36)
GROUP BY s.id, s.name;
