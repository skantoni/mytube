-- =============================================================
-- MyTube — Livestream System Migration
-- Versão: 1.0  |  Charset: utf8mb4  |  Engine: InnoDB
--
-- Uso:
--   Abrir phpMyAdmin → selecionar mytube_db → Importar → carregar este ficheiro
--
-- Ou via terminal:
--   mysql -u root -p mytube_db < database/install_livestream.sql
-- =============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================================
-- TABELA: livestreams (sessões de stream ao vivo)
-- =============================================================

CREATE TABLE IF NOT EXISTS `livestreams` (
  `id`              INT           NOT NULL AUTO_INCREMENT,
  `user_id`         INT           NOT NULL COMMENT 'Utilizador que está a fazer stream',
  `title`           VARCHAR(255)  NOT NULL COMMENT 'Título da livestream',
  `description`     TEXT          DEFAULT NULL COMMENT 'Descrição opcional',
  `thumbnail_path`  VARCHAR(500)  DEFAULT NULL COMMENT 'Caminho para thumbnail (base64 ou ficheiro)',
  `stream_key`      VARCHAR(64)   NOT NULL COMMENT 'Chave única para autenticar o streamer no servidor',
  `status`          ENUM('waiting','live','ended') NOT NULL DEFAULT 'waiting' COMMENT 'Estado actual da stream',
  `viewers_count`   INT           NOT NULL DEFAULT 0 COMMENT 'Espectadores actuais em tempo real',
  `peak_viewers`    INT           NOT NULL DEFAULT 0 COMMENT 'Pico máximo de espectadores',
  `total_views`     INT           NOT NULL DEFAULT 0 COMMENT 'Total de vistas únicas',
  `category`        VARCHAR(50)   DEFAULT NULL COMMENT 'Categoria: gaming, música, conversa, etc.',
  `started_at`      TIMESTAMP     NULL DEFAULT NULL COMMENT 'Quando a stream ficou live',
  `ended_at`        TIMESTAMP     NULL DEFAULT NULL COMMENT 'Quando a stream terminou',
  `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_stream_key` (`stream_key`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_status_created` (`status`, `created_at`),
  KEY `idx_started_at` (`started_at`),
  CONSTRAINT `fk_livestreams_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================
-- TABELA: livestream_messages (chat ao vivo)
-- =============================================================

CREATE TABLE IF NOT EXISTS `livestream_messages` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stream_id`   INT             NOT NULL,
  `user_id`     INT             NOT NULL,
  `message`     TEXT            NOT NULL,
  `type`        ENUM('text','emoji','system','sticker') NOT NULL DEFAULT 'text',
  `created_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_stream_id` (`stream_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_stream_created` (`stream_id`, `created_at`),
  CONSTRAINT `fk_lm_stream` FOREIGN KEY (`stream_id`) REFERENCES `livestreams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lm_user`   FOREIGN KEY (`user_id`)   REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================
-- TABELA: livestream_viewers (registo de espectadores)
-- =============================================================

CREATE TABLE IF NOT EXISTS `livestream_viewers` (
  `id`         INT       NOT NULL AUTO_INCREMENT,
  `stream_id`  INT       NOT NULL,
  `user_id`    INT       DEFAULT NULL COMMENT 'NULL se anónimo (não implementado neste MVP)',
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `joined_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `left_at`    TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_stream_id` (`stream_id`),
  KEY `idx_user_id` (`user_id`),
  UNIQUE KEY `uk_stream_user` (`stream_id`, `user_id`),
  CONSTRAINT `fk_lv_stream` FOREIGN KEY (`stream_id`) REFERENCES `livestreams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lv_user`   FOREIGN KEY (`user_id`)   REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================================
-- CONFIRMAÇAO
-- =============================================================
SELECT 'Tabelas de livestream criadas com sucesso! ✅' AS resultado;
