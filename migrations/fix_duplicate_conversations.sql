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

-- PARTE 2: Adicionar constraint UNIQUE para prevenir duplicatas futuras
-- IMPORTANTE: Esta constraint garante que nunca haverá 2 conversas entre os mesmos usuários

ALTER TABLE conversations 
ADD UNIQUE KEY unique_conversation (
    LEAST(user1_id, user2_id), 
    GREATEST(user1_id, user2_id)
);

-- =========================================
-- VERIFICAÇÃO: Testar se a constraint funciona
-- =========================================

-- Verificar conversas que permaneceram
SELECT 
    COUNT(*) as total_conversations,
    COUNT(DISTINCT CONCAT(LEAST(user1_id, user2_id), '-', GREATEST(user1_id, user2_id))) as unique_pairs
FROM conversations;

-- Os dois números devem ser iguais agora!

-- Para testar manualmente (deve falhar com duplicate entry):
-- INSERT INTO conversations (user1_id, user2_id) VALUES (1, 2);
-- INSERT INTO conversations (user1_id, user2_id) VALUES (2, 1); -- ❌ Erro esperado
