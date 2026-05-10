-- Tabela de histórico de pesquisa persistente
-- Máx 10 entradas por utilizador, entradas >90 dias são eliminadas automaticamente

CREATE TABLE IF NOT EXISTS search_history (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    query       VARCHAR(255)  NOT NULL,
    searched_at DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_user_searched (user_id, searched_at DESC),
    INDEX idx_cleanup       (searched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
