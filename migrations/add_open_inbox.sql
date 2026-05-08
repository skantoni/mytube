-- Adiciona suporte a "caixa de entrada aberta" para contas de suporte
-- Quando open_inbox = 1, qualquer utilizador autenticado pode enviar mensagem sem ser amigo

ALTER TABLE users ADD COLUMN IF NOT EXISTS open_inbox TINYINT(1) NOT NULL DEFAULT 0;

-- Para ativar numa conta de suporte, executar:
-- UPDATE users SET open_inbox = 1 WHERE username = 'nytubesuport';
