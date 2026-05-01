-- =========================================
-- FIX: Race Condition em Conversas Duplicadas
-- Data: 01/05/2026
-- =========================================

-- PARTE 1: Limpar conversas duplicadas existentes
-- (mantém a mais antiga de cada par)

-- Encontrar e deletar conversas duplicadas
DELETE c1 FROM conversations c1
INNER JOIN conversations c2 
WHERE c1.id > c2.id 
AND (
    (c1.user1_id = c2.user1_id AND c1.user2_id = c2.user2_id) OR
    (c1.user1_id = c2.user2_id AND c1.user2_id = c2.user1_id)
);

-- PARTE 2: Adicionar colunas geradas e constraint UNIQUE
-- IMPORTANTE: Cria colunas virtuais que calculam o menor/maior user_id
-- Isso permite criar um índice UNIQUE sem duplicatas independente da ordem

-- Adicionar primeira coluna gerada
ALTER TABLE conversations 
ADD COLUMN user_min INT AS (LEAST(user1_id, user2_id)) STORED;

-- Adicionar segunda coluna gerada
ALTER TABLE conversations 
ADD COLUMN user_max INT AS (GREATEST(user1_id, user2_id)) STORED;

-- Adicionar constraint UNIQUE nas colunas geradas
ALTER TABLE conversations 
ADD UNIQUE KEY unique_conversation (user_min, user_max);

-- =========================================
-- VERIFICAÇÃO: Testar se a constraint funciona
-- =========================================

-- Verificar conversas que permaneceram
SELECT 
    COUNT(*) as total_conversations,
    COUNT(DISTINCT CONCAT(user_min, '-', user_max)) as unique_pairs
FROM conversations;

-- Os dois números devem ser iguais agora!

-- Para testar manualmente (deve falhar com duplicate entry):
-- INSERT INTO conversations (user1_id, user2_id) VALUES (1, 2);
-- INSERT INTO conversations (user1_id, user2_id) VALUES (2, 1); -- ❌ Erro esperado
