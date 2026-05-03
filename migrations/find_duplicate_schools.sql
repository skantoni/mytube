-- ============================================================
-- IDENTIFICAR ESCOLAS DUPLICADAS/SIMILARES
-- ============================================================

-- PASSO 1: Ver TODAS as escolas com quantos users cada uma tem
SELECT 
    s.id,
    s.name,
    s.city,
    s.province,
    s.is_active,
    COUNT(u.id) AS total_users
FROM schools s
LEFT JOIN users u ON u.school_id = s.id
GROUP BY s.id, s.name, s.city, s.province, s.is_active
ORDER BY s.name ASC;

-- ============================================================

-- PASSO 2: Encontrar escolas com nomes MUITO parecidos
-- (mesmo início de nome, agrupa possíveis duplicatas)
SELECT 
    a.id        AS id_A,
    a.name      AS nome_A,
    a.city      AS cidade_A,
    COUNT(ua.id) AS users_A,
    b.id        AS id_B,
    b.name      AS nome_B,
    b.city      AS cidade_B,
    COUNT(ub.id) AS users_B
FROM schools a
JOIN schools b 
    ON a.id < b.id
    AND a.city = b.city
    AND (
        -- nomes com pelo menos 10 caracteres em comum no início
        LEFT(LOWER(a.name), 10) = LEFT(LOWER(b.name), 10)
        OR
        -- um nome contém o outro
        LOWER(a.name) LIKE CONCAT('%', LEFT(LOWER(b.name), 8), '%')
        OR
        LOWER(b.name) LIKE CONCAT('%', LEFT(LOWER(a.name), 8), '%')
    )
LEFT JOIN users ua ON ua.school_id = a.id
LEFT JOIN users ub ON ub.school_id = b.id
GROUP BY a.id, a.name, a.city, b.id, b.name, b.city
ORDER BY a.name ASC;

-- ============================================================

-- PASSO 3: Escolas SEM nenhum user (candidatas a deletar direto)
SELECT 
    s.id,
    s.name,
    s.city,
    s.is_active
FROM schools s
LEFT JOIN users u ON u.school_id = s.id
WHERE u.id IS NULL
ORDER BY s.name ASC;
