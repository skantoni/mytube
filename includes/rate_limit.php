<?php
/**
 * Rate Limiting System
 * 
 * Proteção contra brute force em login, reset password, etc
 * 
 * Estratégias:
 * 1. Limite de tentativas por IP
 * 2. Limite de tentativas por usuário
 * 3. Bloqueio temporário (crescente)
 * 4. Detecção de padrões suspeitos
 */

/**
 * Verificar se IP/usuário está bloqueado por rate limit
 * 
 * @param LazyPDO|PDO $pdo Conexão com banco (aceita LazyPDO wrapper)
 * @param string $action Tipo de ação ('login', 'reset_code', 'api')
 * @param string $identifier IP ou email do usuário
 * @param int $max_attempts Máximo de tentativas permitidas
 * @param int $window_minutes Janela de tempo em minutos
 * @return array ['blocked' => bool, 'attempts' => int, 'remaining' => int, 'reset_at' => int|null]
 */
function rate_limit_check($pdo, string $action, string $identifier, int $max_attempts = 5, int $window_minutes = 15): array {
    // Criar tabela se não existir
    rate_limit_ensure_table($pdo);
    
    // Limpar tentativas antigas (fora da janela de tempo)
    $window_start = date('Y-m-d H:i:s', strtotime("-$window_minutes minutes"));
    $stmt = $pdo->prepare("DELETE FROM rate_limits WHERE action = ? AND identifier = ? AND attempted_at < ?");
    $stmt->execute([$action, $identifier, $window_start]);
    
    // Contar tentativas recentes
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as attempt_count, MIN(attempted_at) as first_attempt
        FROM rate_limits 
        WHERE action = ? AND identifier = ? AND attempted_at >= ?
    ");
    $stmt->execute([$action, $identifier, $window_start]);
    $result = $stmt->fetch();
    
    $attempts = (int)($result['attempt_count'] ?? 0);
    $remaining = max(0, $max_attempts - $attempts);
    $blocked = ($attempts >= $max_attempts);
    
    // Calcular quando o bloqueio será resetado
    $reset_at = null;
    if ($blocked && $result['first_attempt']) {
        $reset_timestamp = strtotime($result['first_attempt']) + ($window_minutes * 60);
        $reset_at = $reset_timestamp;
    }
    
    return [
        'blocked' => $blocked,
        'attempts' => $attempts,
        'remaining' => $remaining,
        'reset_at' => $reset_at,
        'window_minutes' => $window_minutes
    ];
}

/**
 * Registrar tentativa de ação (login, reset, etc)
 * 
 * @param LazyPDO|PDO $pdo Conexão com banco (aceita LazyPDO wrapper)
 * @param string $action Tipo de ação
 * @param string $identifier IP ou email
 * @param bool $success Se a tentativa foi bem-sucedida
 * @return void
 */
function rate_limit_record($pdo, string $action, string $identifier, bool $success = false): void {
    rate_limit_ensure_table($pdo);
    
    // Se foi sucesso, limpar tentativas anteriores
    if ($success) {
        $stmt = $pdo->prepare("DELETE FROM rate_limits WHERE action = ? AND identifier = ?");
        $stmt->execute([$action, $identifier]);
        return;
    }
    
    // Registrar tentativa falhada
    $stmt = $pdo->prepare("
        INSERT INTO rate_limits (action, identifier, attempted_at, ip_address, user_agent)
        VALUES (?, ?, NOW(), ?, ?)
    ");
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    
    $stmt->execute([$action, $identifier, $ip, $user_agent]);
}

/**
 * Obter IP real do cliente (considerando proxies/CDN)
 * 
 * @return string IP do cliente
 */
function rate_limit_get_client_ip(): string {
    // Cloudflare
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    
    // Outros proxies
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    
    if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        return $_SERVER['HTTP_X_REAL_IP'];
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Criar tabela rate_limits se não existir
 * 
 * @param LazyPDO|PDO $pdo Conexão com banco (aceita LazyPDO wrapper)
 * @return void
 */
function rate_limit_ensure_table($pdo): void {
    static $table_checked = false;
    
    if ($table_checked) {
        return;
    }
    
    try {
        // Verificar se tabela existe
        $stmt = $pdo->query("SHOW TABLES LIKE 'rate_limits'");
        if ($stmt->rowCount() > 0) {
            $table_checked = true;
            return;
        }
        
        // Criar tabela
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `rate_limits` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `action` VARCHAR(50) NOT NULL COMMENT 'Tipo: login, reset_code, api',
                `identifier` VARCHAR(255) NOT NULL COMMENT 'IP ou email',
                `attempted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `ip_address` VARCHAR(45) DEFAULT NULL COMMENT 'IP real da tentativa',
                `user_agent` VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_action_identifier` (`action`, `identifier`),
                KEY `idx_attempted_at` (`attempted_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Rate limiting para prevenir brute force';
        ");
        
        $table_checked = true;
        error_log("rate_limits: Tabela criada com sucesso");
    } catch (PDOException $e) {
        error_log("rate_limits: Erro ao criar tabela - " . $e->getMessage());
    }
}

/**
 * Formatar tempo restante para mensagem ao usuário
 * 
 * @param int $reset_timestamp Unix timestamp
 * @return string Tempo formatado (ex: "5 minutos", "1 hora")
 */
function rate_limit_format_time_remaining(int $reset_timestamp): string {
    $seconds = $reset_timestamp - time();
    
    if ($seconds <= 0) {
        return 'agora';
    }
    
    if ($seconds < 60) {
        return "$seconds segundos";
    }
    
    $minutes = ceil($seconds / 60);
    if ($minutes < 60) {
        return "$minutes " . ($minutes == 1 ? 'minuto' : 'minutos');
    }
    
    $hours = ceil($minutes / 60);
    return "$hours " . ($hours == 1 ? 'hora' : 'horas');
}

/**
 * Limpar tentativas antigas (cron job - rodar 1x por dia)
 * 
 * @param LazyPDO|PDO $pdo Conexão com banco (aceita LazyPDO wrapper)
 * @param int $days_to_keep Quantos dias manter histórico
 * @return int Número de registros deletados
 */
function rate_limit_cleanup($pdo, int $days_to_keep = 7): int {
    rate_limit_ensure_table($pdo);
    
    $cutoff_date = date('Y-m-d H:i:s', strtotime("-$days_to_keep days"));
    $stmt = $pdo->prepare("DELETE FROM rate_limits WHERE attempted_at < ?");
    $stmt->execute([$cutoff_date]);
    
    return $stmt->rowCount();
}
