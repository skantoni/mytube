-- =============================================================
-- MyTube — Script de Instalação Limpa
-- Versão: 2.0  |  Charset: utf8mb4  |  Engine: InnoDB
--
-- Uso:
--   mysql -u root -p mytube < database/install.sql
--
-- Ou via phpMyAdmin:
--   Criar base de dados "mytube", seleccionar "Importar" e
--   carregar este ficheiro.
--
-- Credenciais padrão após instalação:
--   Utilizador : admin
--   Password   : admin123   (ALTERE após a primeira entrada)
--
-- Para gerar um novo hash de password:
--   php -r "echo password_hash('nova_senha', PASSWORD_BCRYPT);"
-- =============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+00:00';

-- =============================================================
-- TABELAS BASE (sem dependências externas)
-- =============================================================

CREATE TABLE IF NOT EXISTS `schools` (
  `id`             INT          NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(200) NOT NULL,
  `short_name`     VARCHAR(50)  DEFAULT NULL,
  `logo_path`      VARCHAR(255) DEFAULT NULL,
  `city`           VARCHAR(100) DEFAULT 'Luanda',
  `province`       VARCHAR(100) DEFAULT 'Luanda',
  `total_points`   INT          DEFAULT 0,
  `total_students` INT          DEFAULT 0,
  `total_videos`   INT          DEFAULT 0,
  `is_active`      TINYINT(1)   DEFAULT 1,
  `created_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_school_name` (`name`),
  KEY `idx_total_points` (`total_points`),
  KEY `idx_city` (`city`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `global_notifications` (
  `id`           INT          NOT NULL AUTO_INCREMENT,
  `type`         VARCHAR(50)  NOT NULL,
  `message`      VARCHAR(255) NOT NULL,
  `reference_id` INT          DEFAULT NULL,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `hashtags` (
  `id`           INT          NOT NULL AUTO_INCREMENT,
  `name`         VARCHAR(20)  NOT NULL,
  `slug`         VARCHAR(20)  NOT NULL,
  `posts_count`  INT          NOT NULL DEFAULT 0,
  `is_seed`      TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `last_used_at` TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_hashtag_name` (`name`),
  UNIQUE KEY `uk_hashtag_slug` (`slug`),
  KEY `idx_posts_count` (`posts_count`),
  KEY `idx_last_used_at` (`last_used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================
-- UTILIZADORES
-- =============================================================

CREATE TABLE IF NOT EXISTS `users` (
  `id`               INT          NOT NULL AUTO_INCREMENT,
  `username`         VARCHAR(50)  NOT NULL,
  `email`            VARCHAR(100) NOT NULL,
  `password`         VARCHAR(255) NOT NULL,
  `google_id`        VARCHAR(50)  NULL DEFAULT NULL COMMENT 'Google sub — ID único OAuth',
  `full_name`        VARCHAR(100) NOT NULL,
  `instituicao`      VARCHAR(150) DEFAULT NULL,
  `bio`              TEXT         DEFAULT NULL,
  `profile_picture`  VARCHAR(255) DEFAULT 'default.jpg',
  `followers_count`  INT          DEFAULT 0,
  `following_count`  INT          DEFAULT 0,
  `videos_count`     INT          DEFAULT 0,
  `is_verified`      TINYINT(1)   DEFAULT 0,
  `open_inbox`       TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Qualquer um pode enviar mensagem',
  `role`             ENUM('user','admin','moderator','vip') NOT NULL DEFAULT 'user',
  `school_id`        INT          DEFAULT NULL,
  `ranking_points`   INT          DEFAULT 0,
  `created_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `idx_google_id` (`google_id`),
  KEY `idx_school_id` (`school_id`),
  KEY `idx_ranking_points` (`ranking_points`),
  KEY `idx_school_ranking` (`school_id`, `ranking_points`),
  CONSTRAINT `users_ibfk_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------
-- HISTÓRICO DE SESSÕES (Logins)
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `user_login_history` (
  `id`           INT          NOT NULL AUTO_INCREMENT,
  `user_id`      INT          NOT NULL,
  `logged_in_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_address`   VARCHAR(45)  NULL,
  `user_agent`   VARCHAR(500) NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_logged` (`user_id`, `logged_in_at`),
  KEY `idx_logged_at`   (`logged_in_at`),
  CONSTRAINT `fk_ulh_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================
-- VÍDEOS
-- =============================================================

CREATE TABLE IF NOT EXISTS `videos` (
  `id`                    INT          NOT NULL AUTO_INCREMENT,
  `user_id`               INT          NOT NULL,
  `title`                 VARCHAR(255) NOT NULL,
  `description`           TEXT         DEFAULT NULL,
  `video_path`            VARCHAR(255) NOT NULL,
  `thumbnail_path`        VARCHAR(255) DEFAULT NULL,
  `duration`              INT          DEFAULT NULL,
  `views_count`           INT          DEFAULT 0,
  `likes_count`           INT          DEFAULT 0,
  `comments_count`        INT          DEFAULT 0,
  `trend_score`           INT UNSIGNED NOT NULL DEFAULT 0,
  `shares_count`          INT          DEFAULT 0,
  `is_public`             TINYINT(1)   DEFAULT 1,
  `is_boosted`            TINYINT(1)   NOT NULL DEFAULT 0,
  `moderation_status`     ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  `moderation_score`      DECIMAL(5,4) NULL DEFAULT NULL,
  `moderation_checked_at` TIMESTAMP    NULL DEFAULT NULL,
  `hashtags`              TEXT         DEFAULT NULL,
  `music_name`            VARCHAR(255) DEFAULT '',
  `music_artist`          VARCHAR(255) DEFAULT '',
  `created_at`            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_public_created` (`is_public`, `created_at`),
  KEY `idx_user_public_created` (`user_id`, `is_public`, `created_at`),
  KEY `idx_user_public` (`user_id`, `is_public`),
  KEY `idx_public_trend` (`is_public`, `trend_score`),
  KEY `idx_moderation_status` (`moderation_status`),
  CONSTRAINT `videos_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================
-- RELAÇÕES SOCIAIS
-- =============================================================

CREATE TABLE IF NOT EXISTS `follows` (
  `id`           INT       NOT NULL AUTO_INCREMENT,
  `follower_id`  INT       NOT NULL,
  `following_id` INT       NOT NULL,
  `created_at`   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_follow` (`follower_id`, `following_id`),
  KEY `idx_following_id` (`following_id`),
  CONSTRAINT `follows_ibfk_1` FOREIGN KEY (`follower_id`)  REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `follows_ibfk_2` FOREIGN KEY (`following_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `friend_requests` (
  `id`          INT       NOT NULL AUTO_INCREMENT,
  `sender_id`   INT       NOT NULL,
  `receiver_id` INT       NOT NULL,
  `status`      ENUM('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_pair` (`sender_id`, `receiver_id`),
  KEY `idx_receiver` (`receiver_id`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fr_ibfk_sender`   FOREIGN KEY (`sender_id`)   REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fr_ibfk_receiver` FOREIGN KEY (`receiver_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================
-- CONTEÚDO — COMENTÁRIOS, LIKES, VISTAS, PARTILHAS
-- =============================================================

CREATE TABLE IF NOT EXISTS `comments` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`           INT             NOT NULL,
  `parent_comment_id` BIGINT UNSIGNED DEFAULT NULL,
  `video_id`          INT             NOT NULL,
  `comment_text`      TEXT            NOT NULL,
  `likes_count`       INT             DEFAULT 0,
  `created_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_video_id` (`video_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_parent` (`parent_comment_id`),
  KEY `idx_video_created` (`video_id`, `created_at`),
  KEY `idx_video_parent_created` (`video_id`, `parent_comment_id`, `created_at`),
  KEY `idx_video_updated` (`video_id`, `updated_at`),
  CONSTRAINT `comments_ibfk_1`   FOREIGN KEY (`user_id`)           REFERENCES `users`    (`id`) ON DELETE CASCADE,
  CONSTRAINT `comments_ibfk_2`   FOREIGN KEY (`video_id`)          REFERENCES `videos`   (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_comments_parent` FOREIGN KEY (`parent_comment_id`) REFERENCES `comments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `comment_likes` (
  `id`         INT             NOT NULL AUTO_INCREMENT,
  `user_id`    INT             NOT NULL,
  `comment_id` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_comment` (`user_id`, `comment_id`),
  KEY `idx_user_id`    (`user_id`),
  KEY `idx_comment_id` (`comment_id`),
  CONSTRAINT `comment_likes_ibfk_1` FOREIGN KEY (`user_id`)    REFERENCES `users`    (`id`) ON DELETE CASCADE,
  CONSTRAINT `comment_likes_ibfk_2` FOREIGN KEY (`comment_id`) REFERENCES `comments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `video_likes` (
  `id`         INT       NOT NULL AUTO_INCREMENT,
  `user_id`    INT       NOT NULL,
  `video_id`   INT       NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_video` (`user_id`, `video_id`),
  KEY `idx_video_id` (`video_id`),
  CONSTRAINT `video_likes_ibfk_1` FOREIGN KEY (`user_id`)  REFERENCES `users`  (`id`) ON DELETE CASCADE,
  CONSTRAINT `video_likes_ibfk_2` FOREIGN KEY (`video_id`) REFERENCES `videos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `video_views` (
  `id`         INT          NOT NULL AUTO_INCREMENT,
  `video_id`   INT          NOT NULL,
  `user_id`    INT          DEFAULT NULL,
  `ip_address` VARCHAR(45)  DEFAULT NULL,
  `user_agent` TEXT         DEFAULT NULL,
  `viewed_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id`    (`user_id`),
  KEY `idx_viewed_at`  (`viewed_at`),
  KEY `idx_dedup_user` (`video_id`, `user_id`, `viewed_at`),
  KEY `idx_dedup_ip`   (`video_id`, `ip_address`, `viewed_at`),
  CONSTRAINT `video_views_ibfk_1` FOREIGN KEY (`video_id`) REFERENCES `videos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `video_views_ibfk_2` FOREIGN KEY (`user_id`)  REFERENCES `users`  (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `shares` (
  `id`         INT         NOT NULL AUTO_INCREMENT,
  `video_id`   INT         NOT NULL,
  `user_id`    INT         NOT NULL,
  `platform`   VARCHAR(50) DEFAULT NULL,
  `shared_at`  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_video_id` (`video_id`),
  KEY `idx_user_id`  (`user_id`),
  CONSTRAINT `shares_ibfk_1` FOREIGN KEY (`video_id`) REFERENCES `videos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shares_ibfk_2` FOREIGN KEY (`user_id`)  REFERENCES `users`  (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `video_hashtags` (
  `video_id`   INT       NOT NULL,
  `hashtag_id` INT       NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`video_id`, `hashtag_id`),
  KEY `idx_hashtag_video` (`hashtag_id`, `video_id`),
  CONSTRAINT `fk_video_hashtags_video`   FOREIGN KEY (`video_id`)   REFERENCES `videos`   (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_video_hashtags_hashtag` FOREIGN KEY (`hashtag_id`) REFERENCES `hashtags` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================
-- NOTIFICAÇÕES
-- =============================================================

CREATE TABLE IF NOT EXISTS `notifications` (
  `id`           INT       NOT NULL AUTO_INCREMENT,
  `user_id`      INT       NOT NULL,
  `actor_id`     INT       NOT NULL,
  `type`         VARCHAR(20)  NOT NULL,
  `reference_id` INT          DEFAULT NULL,
  `comment_id`   INT          DEFAULT NULL,
  `message`      TEXT         DEFAULT NULL,
  `is_read`      TINYINT(1)   DEFAULT 0,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_id`           (`user_id`),
  KEY `idx_created_at`        (`created_at`),
  KEY `actor_id`              (`actor_id`),
  KEY `idx_comment_id`        (`comment_id`),
  KEY `idx_user_created`      (`user_id`, `created_at`),
  KEY `idx_user_read`         (`user_id`, `is_read`),
  KEY `idx_user_read_created` (`user_id`, `is_read`, `created_at`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`)  REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `user_global_reads` (
  `user_id`                INT       NOT NULL,
  `global_notification_id` INT       NOT NULL,
  `created_at`             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `global_notification_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `password_resets` (
  `id`         INT         NOT NULL AUTO_INCREMENT,
  `user_id`    INT         NOT NULL,
  `email`      VARCHAR(255) NOT NULL,
  `reset_code` VARCHAR(6)   NOT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME     NOT NULL,
  `used`       TINYINT(1)   DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_email`   (`email`),
  KEY `idx_code`    (`reset_code`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `push_subscriptions` (
  `id`         INT          NOT NULL AUTO_INCREMENT,
  `user_id`    INT          NOT NULL,
  `endpoint`   VARCHAR(500) NOT NULL,
  `p256dh`     VARCHAR(500) NOT NULL,
  `auth`       VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_endpoint` (`endpoint`(191)),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `push_sub_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================
-- CHAT 1:1
-- =============================================================

CREATE TABLE IF NOT EXISTS `conversations` (
  `id`         INT       NOT NULL AUTO_INCREMENT,
  `user1_id`   INT       NOT NULL,
  `user2_id`   INT       NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user2_id`  (`user2_id`),
  KEY `idx_users` (`user1_id`, `user2_id`),
  CONSTRAINT `conversations_ibfk_1` FOREIGN KEY (`user1_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `conversations_ibfk_2` FOREIGN KEY (`user2_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `messages` (
  `id`                       INT          NOT NULL AUTO_INCREMENT,
  `conversation_id`          INT          NOT NULL,
  `sender_id`                INT          NOT NULL,
  `receiver_id`              INT          NOT NULL,
  `message`                  TEXT         NOT NULL,
  `reply_to_message_id`      INT          DEFAULT NULL,
  `type`                     ENUM('text','audio','file','image','video','sticker') DEFAULT 'text',
  `file_url`                 VARCHAR(500) DEFAULT NULL,
  `status`                   ENUM('sent','delivered','read') DEFAULT 'sent',
  `deleted_for_sender`       TINYINT(1)   DEFAULT 0,
  `deleted_for_receiver`     TINYINT(1)   DEFAULT 0,
  `deleted_for_all`          TINYINT(1)   DEFAULT 0,
  `is_edited`                TINYINT(1)   DEFAULT 0,
  `forwarded_from_message_id`  INT        DEFAULT NULL,
  `forwarded_from_user_id`     INT        DEFAULT NULL,
  `forwarded_from_username`    VARCHAR(100) DEFAULT NULL,
  `created_at`               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`               TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `reply_to_message_id` (`reply_to_message_id`),
  KEY `idx_conversation`    (`conversation_id`),
  KEY `idx_sender`          (`sender_id`),
  KEY `idx_receiver`        (`receiver_id`),
  KEY `idx_created`         (`created_at`),
  KEY `idx_status`          (`status`),
  KEY `idx_deleted`         (`deleted_for_all`),
  KEY `idx_forwarded`       (`forwarded_from_message_id`),
  CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`conversation_id`)     REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`sender_id`)            REFERENCES `users`         (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_ibfk_3` FOREIGN KEY (`receiver_id`)          REFERENCES `users`         (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_ibfk_4` FOREIGN KEY (`reply_to_message_id`)  REFERENCES `messages`      (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `message_reactions` (
  `id`         INT         NOT NULL AUTO_INCREMENT,
  `message_id` INT         NOT NULL,
  `user_id`    INT         NOT NULL,
  `emoji`      VARCHAR(10) NOT NULL,
  `created_at` TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_reaction` (`message_id`, `user_id`, `emoji`),
  KEY `idx_message_reactions_message` (`message_id`),
  KEY `idx_message_reactions_user`    (`user_id`),
  CONSTRAINT `message_reactions_ibfk_1` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `message_reactions_ibfk_2` FOREIGN KEY (`user_id`)    REFERENCES `users`    (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `typing_status` (
  `id`              INT       NOT NULL AUTO_INCREMENT,
  `conversation_id` INT       NOT NULL,
  `user_id`         INT       NOT NULL,
  `is_typing`       TINYINT(1) DEFAULT 0,
  `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_typing` (`conversation_id`, `user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `typing_status_ibfk_1` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `typing_status_ibfk_2` FOREIGN KEY (`user_id`)         REFERENCES `users`         (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `hidden_conversations` (
  `user_id`         INT       NOT NULL,
  `conversation_id` INT       NOT NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `conversation_id`),
  KEY `idx_conv` (`conversation_id`),
  CONSTRAINT `hc_ibfk_user` FOREIGN KEY (`user_id`)         REFERENCES `users`         (`id`) ON DELETE CASCADE,
  CONSTRAINT `hc_ibfk_conv` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `user_online_status` (
  `user_id`   INT       NOT NULL,
  `is_online` TINYINT(1) DEFAULT 0,
  `last_seen` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  KEY `idx_online` (`is_online`, `last_seen`),
  CONSTRAINT `user_online_status_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================
-- CHAT DE GRUPO
-- =============================================================

CREATE TABLE IF NOT EXISTS `chats` (
  `id`            INT          NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(100) DEFAULT NULL,
  `is_group`      TINYINT(1)   DEFAULT 0,
  `group_picture` VARCHAR(255) DEFAULT NULL,
  `created_by`    INT          DEFAULT NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_is_group`   (`is_group`),
  KEY `idx_created_by` (`created_by`),
  CONSTRAINT `chats_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `chat_participants` (
  `id`        INT       NOT NULL AUTO_INCREMENT,
  `chat_id`   INT       NOT NULL,
  `user_id`   INT       NOT NULL,
  `joined_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_chat_user` (`chat_id`, `user_id`),
  KEY `idx_chat_id` (`chat_id`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `chat_participants_ibfk_1` FOREIGN KEY (`chat_id`) REFERENCES `chats` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chat_participants_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `group_messages` (
  `id`                  INT       NOT NULL AUTO_INCREMENT,
  `group_id`            INT       NOT NULL,
  `sender_id`           INT       NOT NULL,
  `message`             TEXT      DEFAULT NULL,
  `type`                ENUM('text','audio','file','image','video','sticker') NOT NULL DEFAULT 'text',
  `file_url`            VARCHAR(500) DEFAULT NULL,
  `reply_to_message_id` INT       DEFAULT NULL,
  `is_deleted`          TINYINT(1) NOT NULL DEFAULT 0,
  `is_edited`           TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_group_id`  (`group_id`),
  KEY `idx_sender_id` (`sender_id`),
  KEY `idx_created`   (`created_at`),
  KEY `idx_reply`     (`reply_to_message_id`),
  CONSTRAINT `gm_ibfk_group`  FOREIGN KEY (`group_id`)            REFERENCES `chats`          (`id`) ON DELETE CASCADE,
  CONSTRAINT `gm_ibfk_sender` FOREIGN KEY (`sender_id`)           REFERENCES `users`          (`id`) ON DELETE CASCADE,
  CONSTRAINT `gm_ibfk_reply`  FOREIGN KEY (`reply_to_message_id`) REFERENCES `group_messages` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================
-- RANKINGS / GAMIFICAÇÃO
-- =============================================================

CREATE TABLE IF NOT EXISTS `best_mytuber_candidates` (
  `id`                INT            NOT NULL AUTO_INCREMENT,
  `week_start`        DATE           NOT NULL,
  `scope`             ENUM('global','school') NOT NULL DEFAULT 'global',
  `school_id`         INT            DEFAULT NULL,
  `user_id`           INT            NOT NULL,
  `raw_score`         DECIMAL(10,2)  DEFAULT 0.00,
  `final_score`       DECIMAL(10,2)  DEFAULT 0.00,
  `consistency_score` DECIMAL(8,2)   DEFAULT 0.00,
  `quality_score`     DECIMAL(8,2)   DEFAULT 0.00,
  `engagement_score`  DECIMAL(8,2)   DEFAULT 0.00,
  `impact_score`      DECIMAL(8,2)   DEFAULT 0.00,
  `behavior_score`    DECIMAL(8,2)   DEFAULT 0.00,
  `cooldown_penalty`  DECIMAL(5,2)   DEFAULT 0.00,
  `rising_star_bonus` TINYINT(1)     DEFAULT 0,
  `videos_count`      INT            DEFAULT 0,
  `position`          INT            DEFAULT 0,
  `created_at`        TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_week_scope` (`week_start`, `scope`, `school_id`),
  KEY `idx_user_week`  (`user_id`, `week_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `best_mytuber_weekly` (
  `id`                  INT            NOT NULL AUTO_INCREMENT,
  `user_id`             INT            NOT NULL,
  `week_start`          DATE           NOT NULL,
  `week_end`            DATE           NOT NULL,
  `scope`               ENUM('global','school') NOT NULL DEFAULT 'global',
  `school_id`           INT            DEFAULT NULL,
  `total_score`         DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
  `consistency_score`   DECIMAL(8,2)   DEFAULT 0.00,
  `quality_score`       DECIMAL(8,2)   DEFAULT 0.00,
  `engagement_score`    DECIMAL(8,2)   DEFAULT 0.00,
  `impact_score`        DECIMAL(8,2)   DEFAULT 0.00,
  `behavior_score`      DECIMAL(8,2)   DEFAULT 0.00,
  `cooldown_penalty`    DECIMAL(5,2)   DEFAULT 0.00,
  `rising_star_bonus`   TINYINT(1)     DEFAULT 0,
  `videos_count`        INT            DEFAULT 0,
  `total_likes`         INT            DEFAULT 0,
  `total_views`         INT            DEFAULT 0,
  `total_comments`      INT            DEFAULT 0,
  `new_followers`       INT            DEFAULT 0,
  `badge_visible_from`  DATETIME       NOT NULL,
  `badge_visible_until` DATETIME       NOT NULL,
  `created_at`          TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_scope_school_week` (`scope`, `school_id`, `week_start`),
  KEY `idx_user_week`      (`user_id`, `week_start`),
  KEY `idx_scope_week`     (`scope`, `week_start`),
  KEY `idx_school_week`    (`school_id`, `week_start`),
  KEY `idx_badge_visibility` (`badge_visible_from`, `badge_visible_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `school_weekly_stats` (
  `id`            INT       NOT NULL AUTO_INCREMENT,
  `school_id`     INT       NOT NULL,
  `week_start`    DATE      NOT NULL,
  `week_end`      DATE      NOT NULL,
  `points`        INT       DEFAULT 0,
  `videos_count`  INT       DEFAULT 0,
  `views_count`   INT       DEFAULT 0,
  `likes_count`   INT       DEFAULT 0,
  `new_creators`  INT       DEFAULT 0,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_school_week` (`school_id`, `week_start`),
  KEY `idx_week_points` (`week_start`, `points`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================
-- BOOST / PUBLICIDADE
-- =============================================================

CREATE TABLE IF NOT EXISTS `boost_clicks` (
  `id`         INT       NOT NULL AUTO_INCREMENT,
  `video_id`   INT       NOT NULL,
  `user_id`    INT       NOT NULL,
  `click_type` ENUM('view','like','comment','share') DEFAULT 'view',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_video`      (`video_id`),
  KEY `idx_video_date` (`video_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `boost_impressions` (
  `id`              INT       NOT NULL AUTO_INCREMENT,
  `video_id`        INT       NOT NULL,
  `user_id`         INT       NOT NULL,
  `impression_date` DATE      NOT NULL,
  `impressions`     INT       DEFAULT 1,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_daily` (`video_id`, `user_id`, `impression_date`),
  KEY `idx_video_date` (`video_id`, `impression_date`),
  KEY `idx_user_date`  (`user_id`,  `impression_date`),
  KEY `idx_date`       (`impression_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =============================================================
-- DADOS INICIAIS
-- =============================================================

-- Utilizador administrador padrão
-- Password: admin123 — ALTERE após a primeira entrada via phpMyAdmin ou:
--   UPDATE users SET password = '<hash>' WHERE username = 'admin';
-- Para gerar um hash: php -r "echo password_hash('nova_senha', PASSWORD_BCRYPT);"
INSERT INTO `users`
  (`username`, `email`, `password`, `full_name`, `role`, `is_verified`)
VALUES
  ('admin', 'admin@mytube.local',
   '$2y$10$RIGj97hq7MxFEeJHdBV3pu5qwXr9bTq1mcaE4i5GPDHWqSdG5Dlvy',
   'Administrador', 'admin', 1)
ON DUPLICATE KEY UPDATE `role` = 'admin', `is_verified` = 1;

-- Registar admin na tabela de presença online
INSERT IGNORE INTO `user_online_status` (`user_id`, `is_online`)
SELECT `id`, 0 FROM `users` WHERE `username` = 'admin';


SET FOREIGN_KEY_CHECKS = 1;
