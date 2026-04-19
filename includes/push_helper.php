<?php
/**
 * Helper para enviar Push Notifications via Node.js chat-server
 * 
 * Uso: sendPushNotification($pdo, $userId, 'Título', 'Corpo', '/my/index.php');
 * Uso em massa: sendPushToMultiple($pdo, [userId1, userId2], 'Título', 'Corpo', '/my/index.php');
 */

/**
 * Verifica se push notifications estão habilitadas
 */
function isPushEnabled(): bool {
    static $enabled = null;
    if ($enabled === null) {
        $configFile = __DIR__ . '/push_config.php';
        if (file_exists($configFile)) {
            require_once $configFile;
            $enabled = defined('PUSH_ENABLED') && PUSH_ENABLED;
        } else {
            $enabled = false;
        }
    }
    return $enabled;
}

/**
 * Envia push notification para um utilizador específico
 * Chama o endpoint /api/send-push do chat-server Node.js
 * 
 * @param object $pdo     Instância PDO/LazyPDO
 * @param int    $userId  ID do utilizador destinatário
 * @param string $title   Título da notificação
 * @param string $body    Corpo da notificação
 * @param string $url     URL para abrir ao clicar (opcional)
 * @param string $icon    Ícone personalizado (opcional)
 * @return bool           True se enviou pelo menos 1 push com sucesso
 */
function sendPushNotification($pdo, int $userId, string $title, string $body, string $url = '', string $icon = ''): bool {
    if (!isPushEnabled()) {
        return false;
    }
    
    try {
        // Buscar subscrições push do utilizador
        $stmt = $pdo->prepare("
            SELECT endpoint, p256dh, auth 
            FROM push_subscriptions 
            WHERE user_id = ?
        ");
        $stmt->execute([$userId]);
        $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($subscriptions)) {
            return false;
        }
        
        // Gerar tag única para evitar sobreposição de notificações
        $tag = 'notif_' . $userId . '_' . time() . '_' . mt_rand(1000, 9999);
        
        // Garantir URL absoluto para funcionar em produção
        if ($url && strpos($url, 'http') !== 0) {
            // Se URL relativo, manter como está (service worker resolve)
            $url = $url;
        }
        
        // Enviar via chat-server Node.js
        $payload = json_encode([
            'subscriptions' => $subscriptions,
            'notification' => [
                'title' => mb_substr($title, 0, 100),
                'body' => mb_substr($body, 0, 200),
                'url' => $url ?: '/index.php',
                'icon' => $icon ?: '/assets/images/logo_icon.png',
                'tag' => $tag,
            ]
        ]);
        
        $ch = curl_init('http://localhost:3001/api/send-push');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 1,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Limpar subscrições expiradas retornadas pelo servidor
        if ($httpCode === 200 && $response) {
            $result = json_decode($response, true);
            if (!empty($result['expired'])) {
                $placeholders = implode(',', array_fill(0, count($result['expired']), '?'));
                $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint IN ($placeholders)")
                    ->execute($result['expired']);
            }
        }
        
        return $httpCode === 200;
        
    } catch (Exception $e) {
        error_log("push_helper: Erro ao enviar push para user $userId: " . $e->getMessage());
        return false;
    }
}

/**
 * Envia push para múltiplos utilizadores de uma vez
 * Agrupa todas as subscrições e faz uma única chamada ao Node.js
 * 
 * @param object $pdo      Instância PDO/LazyPDO
 * @param array  $userIds  Array de IDs de utilizadores
 * @param string $title    Título da notificação
 * @param string $body     Corpo da notificação
 * @param string $url      URL para abrir ao clicar
 * @return bool
 */
function sendPushToMultiple($pdo, array $userIds, string $title, string $body, string $url = ''): bool {
    if (!isPushEnabled() || empty($userIds)) {
        return false;
    }
    
    try {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $pdo->prepare("
            SELECT endpoint, p256dh, auth 
            FROM push_subscriptions 
            WHERE user_id IN ($placeholders)
        ");
        $stmt->execute($userIds);
        $subscriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($subscriptions)) {
            return false;
        }
        
        // Gerar tag única para cada grupo de notificações
        $tag = 'notif_batch_' . time() . '_' . mt_rand(1000, 9999);
        
        $payload = json_encode([
            'subscriptions' => $subscriptions,
            'notification' => [
                'title' => mb_substr($title, 0, 100),
                'body' => mb_substr($body, 0, 200),
                'url' => $url ?: '/index.php',
                'icon' => '/assets/images/logo_icon.png',
                'tag' => $tag,
            ]
        ]);
        
        $ch = curl_init('http://localhost:3001/api/send-push');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 1,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && $response) {
            $result = json_decode($response, true);
            if (!empty($result['expired'])) {
                $ph = implode(',', array_fill(0, count($result['expired']), '?'));
                $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint IN ($ph)")
                    ->execute($result['expired']);
            }
        }
        
        return $httpCode === 200;
        
    } catch (Exception $e) {
        error_log("push_helper: Erro ao enviar push em massa: " . $e->getMessage());
        return false;
    }
}
