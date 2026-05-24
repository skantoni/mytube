-- =====================================================
-- Sistema de Anúncios / Campanhas Patrocinadas
-- MyTube - Migration
-- =====================================================

-- Tabela de planos de patrocínio (configurável)
CREATE TABLE IF NOT EXISTS `ad_plans` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(80)  NOT NULL,
    `days`        TINYINT UNSIGNED NOT NULL,
    `price_kz`    INT UNSIGNED NOT NULL,
    `description` VARCHAR(255) NULL,
    `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
    `sort_order`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed: planos padrão
INSERT IGNORE INTO `ad_plans` (`id`, `name`, `days`, `price_kz`, `description`, `sort_order`) VALUES
(1, 'Básico',    3,  1500, 'Ideal para testar o alcance',       1),
(2, 'Standard',  7,  2800, 'Melhor custo-benefício',            2),
(3, 'Premium',  14,  3500, 'Máximo alcance e exposição',        3);

-- Tabela principal de campanhas
CREATE TABLE IF NOT EXISTS `ad_campaigns` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`         INT(11) NOT NULL,
    `video_id`        INT(11) NOT NULL,
    `plan_id`         INT UNSIGNED NOT NULL,

    -- Dados do plano no momento da compra (snapshot)
    `plan_name`       VARCHAR(80)  NOT NULL,
    `plan_days`       TINYINT UNSIGNED NOT NULL,
    `plan_price_kz`   INT UNSIGNED NOT NULL,

    -- Segmentação de público
    `target_gender`   ENUM('all','male','female') NOT NULL DEFAULT 'all',
    `target_age_min`  TINYINT UNSIGNED NULL,
    `target_age_max`  TINYINT UNSIGNED NULL,
    `target_location` VARCHAR(120) NULL,   -- ex: "Luanda", "Angola"
    `target_interests`JSON         NULL,   -- ex: ["música","desporto"]

    -- Estado
    `status`          ENUM('pending','active','paused','expired','rejected') NOT NULL DEFAULT 'pending',
    `rejection_reason`VARCHAR(255) NULL,

    -- Datas
    `paid_at`         TIMESTAMP    NULL,
    `starts_at`       TIMESTAMP    NULL,
    `ends_at`         TIMESTAMP    NULL,
    `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Métricas
    `impressions`     INT UNSIGNED NOT NULL DEFAULT 0,
    `clicks`          INT UNSIGNED NOT NULL DEFAULT 0,
    `budget_spent_kz` INT UNSIGNED NOT NULL DEFAULT 0,

    INDEX `idx_user`   (`user_id`),
    INDEX `idx_video`  (`video_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_ends`   (`ends_at`),
    FOREIGN KEY (`user_id`)  REFERENCES `users`(`id`)  ON DELETE CASCADE,
    FOREIGN KEY (`video_id`) REFERENCES `videos`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`plan_id`)  REFERENCES `ad_plans`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabela de eventos de tracking (impressões, cliques, etc.)
CREATE TABLE IF NOT EXISTS `ad_events` (
    `id`           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `campaign_id`  INT UNSIGNED NOT NULL,
    `event_type`   ENUM('impression','click') NOT NULL,
    `viewer_id`    INT(11) NULL,   -- NULL = anónimo
    `ip_hash`      VARCHAR(64)  NULL,   -- SHA-256 do IP (privacidade)
    `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_campaign_type` (`campaign_id`, `event_type`),
    INDEX `idx_created`       (`created_at`),
    FOREIGN KEY (`campaign_id`) REFERENCES `ad_campaigns`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
