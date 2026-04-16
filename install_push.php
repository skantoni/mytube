<?php
/**
 * Instalador do Sistema de Push Notifications
 * Cria a tabela push_subscriptions e gera chaves VAPID
 * 
 * Executar uma vez: php install_push.php (CLI) ou aceder via browser (admin)
 */

require_once 'includes/config.php';

$isCli = php_sapi_name() === 'cli';

function out($msg, $isCli) {
    echo $isCli ? "$msg\n" : "<p>$msg</p>";
}

if (!$isCli) {
    // Verificar se é admin
    if (!isLoggedIn() || !isAdminUser()) {
        http_response_code(403);
        echo "Acesso negado. Só o admin pode executar este script.";
        exit;
    }
    echo "<!DOCTYPE html><html><head><title>Instalação Push Notifications</title></head><body>";
    echo "<h1>Instalação Push Notifications</h1>";
}

try {
    // 1. Criar tabela push_subscriptions
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS push_subscriptions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            endpoint VARCHAR(500) NOT NULL,
            p256dh VARCHAR(500) NOT NULL,
            auth VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_endpoint (endpoint(450)),
            INDEX idx_user_id (user_id),
            CONSTRAINT fk_push_user FOREIGN KEY (user_id) 
                REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    out("✅ Tabela push_subscriptions criada/verificada.", $isCli);

    // 2. Verificar se chaves VAPID já existem no .env do chat-server
    $envPath = __DIR__ . '/chat-server/.env';
    $envContent = file_exists($envPath) ? file_get_contents($envPath) : '';
    
    if (strpos($envContent, 'VAPID_PUBLIC_KEY=') !== false && 
        preg_match('/VAPID_PUBLIC_KEY=(.+)/', $envContent, $m) && 
        strlen(trim($m[1])) > 10) {
        out("✅ Chaves VAPID já existem no .env do chat-server.", $isCli);
        preg_match('/VAPID_PUBLIC_KEY=(.+)/', $envContent, $pubMatch);
        $publicKey = trim($pubMatch[1]);
    } else {
        out("⏳ Chaves VAPID não encontradas. Gerando via Node.js...", $isCli);
        
        // Gerar chaves VAPID usando o módulo web-push do Node.js
        $nodeScript = 'const webpush = require("web-push"); const keys = webpush.generateVAPIDKeys(); console.log(JSON.stringify(keys));';
        $cmd = 'cd "' . __DIR__ . '/chat-server" && node -e ' . escapeshellarg($nodeScript) . ' 2>&1';
        $output = shell_exec($cmd);
        
        if ($output) {
            $keys = json_decode(trim($output), true);
            if ($keys && isset($keys['publicKey']) && isset($keys['privateKey'])) {
                $publicKey = $keys['publicKey'];
                $privateKey = $keys['privateKey'];
                
                // Adicionar ao .env do chat-server
                $vapidConfig = "\n# Web Push VAPID Keys (gerado automaticamente)\nVAPID_PUBLIC_KEY={$publicKey}\nVAPID_PRIVATE_KEY={$privateKey}\nVAPID_EMAIL=mailto:admin@mytube.com\n";
                file_put_contents($envPath, $envContent . $vapidConfig);
                
                out("✅ Chaves VAPID geradas e salvas no .env do chat-server.", $isCli);
            } else {
                out("❌ Erro ao gerar chaves VAPID. Instale web-push primeiro: cd chat-server && npm install web-push", $isCli);
                out("Output: " . htmlspecialchars($output), $isCli);
            }
        } else {
            out("❌ Erro ao executar Node.js. Verifique se Node.js está instalado e web-push está no chat-server.", $isCli);
        }
    }
    
    // 3. Criar/atualizar push_config.php com a chave pública
    if (isset($publicKey) && $publicKey) {
        $configContent = "<?php\n/**\n * Configuração Web Push Notifications\n * Gerado automaticamente por install_push.php\n */\ndefine('VAPID_PUBLIC_KEY', " . var_export($publicKey, true) . ");\ndefine('PUSH_ENABLED', true);\n";
        file_put_contents(__DIR__ . '/includes/push_config.php', $configContent);
        out("✅ Ficheiro includes/push_config.php criado com chave pública VAPID.", $isCli);
    }
    
    out("", $isCli);
    out("🎉 Instalação concluída! Próximos passos:", $isCli);
    out("1. Instale web-push no chat-server: cd chat-server && npm install web-push", $isCli);
    out("2. Reinicie o chat-server: npm run dev", $isCli);
    out("3. Os utilizadores verão um popup para ativar notificações push.", $isCli);

} catch (Exception $e) {
    out("❌ Erro: " . $e->getMessage(), $isCli);
}

if (!$isCli) {
    echo "</body></html>";
}
