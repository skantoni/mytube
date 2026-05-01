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

-- PARTE 2: Adicionar colunas e trigger para manter ordem normalizada
-- Abordagem compatível com todas as versões do MySQL/MariaDB

-- Adicionar colunas (INT normal, não geradas)
ALTER TABLE conversations 
ADD COLUMN user_min INT DEFAULT NULL,
ADD COLUMN user_max INT DEFAULT NULL;

-- Popular colunas existentes
UPDATE conversations 
SET user_min = LEAST(user1_id, user2_id),
    user_max = GREATEST(user1_id, user2_id);

-- Tornar colunas NOT NULL
ALTER TABLE conversations 
MODIFY COLUMN user_min INT NOT NULL,
MODIFY COLUMN user_max INT NOT NULL;

-- Adicionar constraint UNIQUE
ALTER TABLE conversations 
ADD UNIQUE KEY unique_conversation (user_min, user_max);

-- Criar trigger para manter as colunas atualizadas automaticamente
DELIMITER $$

CREATE TRIGGER conversations_before_insert 
BEFORE INSERT ON conversations
FOR EACH ROW
BEGIN
    SET NEW.user_min = LEAST(NEW.user1_id, NEW.user2_id);
    SET NEW.user_max = GREATEST(NEW.user1_id, NEW.user2_id);
END$$

CREATE TRIGGER conversations_before_update 
BEFORE UPDATE ON conversations
FOR EACH ROW
BEGIN
    SET NEW.user_min = LEAST(NEW.user1_id, NEW.user2_id);
    SET NEW.user_max = GREATEST(NEW.user1_id, NEW.user2_id);
END$$

DELIMITER ;

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
