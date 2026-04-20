-- Migração: Tabela Rate Limiting
-- Data: 20/04/2026
-- Proteção contra brute force em login e reset de senha

CREATE TABLE IF NOT EXISTS `rate_limits` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `action` VARCHAR(50) NOT NULL COMMENT 'Tipo: login, login_user, reset_code, reset_code_email, api',
    `identifier` VARCHAR(255) NOT NULL COMMENT 'IP ou email',
    `attempted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'IP real da tentativa',
    `user_agent` VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_action_identifier` (`action`, `identifier`),
    KEY `idx_attempted_at` (`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Rate limiting para prevenir brute force';

-- Índice para limpeza de registros antigos
CREATE INDEX idx_cleanup ON rate_limits(attempted_at);
