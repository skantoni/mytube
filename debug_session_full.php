<?php
// NÃO carregar config.php ainda - vamos ver o estado RAW
session_start();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug Sessão Completo</title>
    <style>
        body {
            font-family: monospace;
            background: #0a0a0a;
            color: #00ff00;
            padding: 20px;
            font-size: 12px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: #1a1a1a;
            padding: 20px;
            border-radius: 8px;
            border: 2px solid #00ff00;
        }
        h1 { color: #00ff00; text-align: center; }
        h2 { 
            color: #ffff00; 
            margin-top: 30px; 
            padding: 10px;
            background: #2a2a2a;
            border-left: 5px solid #ffff00;
        }
        h3 { color: #00ffff; margin-top: 20px; }
        .info { color: #00ffff; }
        .value { color: #ffffff; }
        .ok { color: #00ff00; }
        .warn { color: #ffaa00; }
        .error { color: #ff0000; }
        pre {
            background: #0a0a0a;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
            border: 1px solid #333;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        th, td {
            padding: 8px;
            text-align: left;
            border: 1px solid #333;
        }
        th {
            background: #2a2a2a;
            color: #ffff00;
        }
        .test-link {
            display: inline-block;
            margin: 10px 5px;
            padding: 12px 20px;
            background: #0066cc;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-weight: bold;
        }
        .test-link:hover { background: #0088ee; }
        .copy-btn {
            background: #cc6600;
            color: white;
            border: none;
            padding: 5px 10px;
            cursor: pointer;
            border-radius: 3px;
            font-family: monospace;
        }
        .instructions {
            background: #2a2a2a;
            padding: 15px;
            border-left: 5px solid #00ffff;
            margin: 20px 0;
        }
        .instructions li {
            margin: 8px 0;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 DEBUG COMPLETO DE SESSÃO - MyTube</h1>
        
        <?php
        // Tentar incluir config para ver variáveis
        $config_loaded = false;
        try {
            require_once __DIR__ . '/includes/config.php';
            $config_loaded = true;
        } catch (Exception $e) {
            echo "<div class='error'>❌ Erro ao carregar config.php: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
        ?>
        
        <h2>🌐 1. INFORMAÇÕES DO AMBIENTE</h2>
        <table>
            <tr>
                <th>Parâmetro</th>
                <th>Valor</th>
                <th>Status</th>
            </tr>
            <tr>
                <td>HTTPS Ativo</td>
                <td><?php echo !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'SIM' : 'NÃO'; ?></td>
                <td class="<?php echo !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'ok' : 'warn'; ?>">
                    <?php echo !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? '✓' : '⚠'; ?>
                </td>
            </tr>
            <tr>
                <td>Servidor</td>
                <td><?php echo htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'N/A'); ?></td>
                <td class="info">ℹ</td>
            </tr>
            <tr>
                <td>PHP Version</td>
                <td><?php echo PHP_VERSION; ?></td>
                <td class="info">ℹ</td>
            </tr>
            <tr>
                <td>Request URI</td>
                <td><?php echo htmlspecialchars($_SERVER['REQUEST_URI'] ?? 'N/A'); ?></td>
                <td class="info">ℹ</td>
            </tr>
            <tr>
                <td>HTTP Host</td>
                <td><?php echo htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'N/A'); ?></td>
                <td class="info">ℹ</td>
            </tr>
            <tr>
                <td>Referer</td>
                <td><?php echo htmlspecialchars($_SERVER['HTTP_REFERER'] ?? '(vazio - navegação direta)'); ?></td>
                <td class="<?php echo empty($_SERVER['HTTP_REFERER']) ? 'warn' : 'info'; ?>">
                    <?php echo empty($_SERVER['HTTP_REFERER']) ? '⚠ Link externo' : 'ℹ'; ?>
                </td>
            </tr>
            <tr>
                <td>User Agent</td>
                <td style="font-size: 10px;"><?php echo htmlspecialchars($_SERVER['HTTP_USER_AGENT'] ?? 'N/A'); ?></td>
                <td class="info">ℹ</td>
            </tr>
        </table>

        <h2>🍪 2. CONFIGURAÇÃO DE COOKIES DE SESSÃO</h2>
        <?php
        $params = session_get_cookie_params();
        ?>
        <table>
            <tr>
                <th>Parâmetro</th>
                <th>Valor Configurado</th>
                <th>Análise</th>
            </tr>
            <tr>
                <td>Session Name</td>
                <td><?php echo session_name(); ?></td>
                <td class="info">ℹ Nome do cookie</td>
            </tr>
            <tr>
                <td>Session ID</td>
                <td><?php echo session_id(); ?></td>
                <td class="info">ℹ ID atual</td>
            </tr>
            <tr>
                <td>Lifetime</td>
                <td><?php echo $params['lifetime']; ?> segundos (<?php echo round($params['lifetime']/3600, 1); ?> horas)</td>
                <td class="<?php echo $params['lifetime'] > 0 ? 'ok' : 'warn'; ?>">
                    <?php echo $params['lifetime'] > 0 ? '✓' : '⚠'; ?>
                </td>
            </tr>
            <tr>
                <td>Path</td>
                <td><?php echo htmlspecialchars($params['path']); ?></td>
                <td class="<?php echo $params['path'] === '/' || !empty($params['path']) ? 'ok' : 'error'; ?>">
                    <?php 
                    if ($params['path'] === '/') {
                        echo '✓ Raiz do domínio';
                    } elseif (!empty($params['path'])) {
                        echo '⚠ Subdiretório: ' . $params['path'];
                    } else {
                        echo '❌ Vazio!';
                    }
                    ?>
                </td>
            </tr>
            <tr>
                <td>Domain</td>
                <td><?php echo empty($params['domain']) ? '(vazio - domínio atual)' : htmlspecialchars($params['domain']); ?></td>
                <td class="<?php echo empty($params['domain']) ? 'ok' : 'warn'; ?>">
                    <?php echo empty($params['domain']) ? '✓ Correto' : '⚠ Definido'; ?>
                </td>
            </tr>
            <tr>
                <td>Secure</td>
                <td><?php echo $params['secure'] ? 'SIM (apenas HTTPS)' : 'NÃO (HTTP permitido)'; ?></td>
                <td class="<?php 
                    $is_https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
                    if ($is_https && $params['secure']) {
                        echo 'ok">✓ Correto para HTTPS';
                    } elseif (!$is_https && !$params['secure']) {
                        echo 'ok">✓ Correto para HTTP';
                    } else {
                        echo 'error">❌ INCOMPATÍVEL';
                    }
                ?>
                </td>
            </tr>
            <tr>
                <td>HttpOnly</td>
                <td><?php echo $params['httponly'] ? 'SIM' : 'NÃO'; ?></td>
                <td class="<?php echo $params['httponly'] ? 'ok' : 'error'; ?>">
                    <?php echo $params['httponly'] ? '✓ Seguro' : '❌ INSEGURO'; ?>
                </td>
            </tr>
            <tr>
                <td>SameSite</td>
                <td><?php echo $params['samesite'] ?? 'N/A'; ?></td>
                <td class="<?php 
                    $samesite = $params['samesite'] ?? '';
                    if ($samesite === 'Lax') {
                        echo 'ok">✓ Correto (permite links externos)';
                    } elseif ($samesite === 'Strict') {
                        echo 'error">❌ BLOQUEIA links externos';
                    } elseif ($samesite === 'None') {
                        echo 'warn">⚠ Muito permissivo';
                    } else {
                        echo 'warn">⚠ Não definido';
                    }
                ?>
                </td>
            </tr>
        </table>

        <h2>📦 3. COOKIES RECEBIDOS PELO SERVIDOR</h2>
        <pre><?php 
        if (empty($_COOKIE)) {
            echo "❌ NENHUM COOKIE RECEBIDO!\n";
            echo "   Isso significa que o navegador não enviou cookies nesta requisição.\n";
            echo "   Possíveis causas:\n";
            echo "   - Cookie foi bloqueado por SameSite Strict\n";
            echo "   - Cookie expirou\n";
            echo "   - Cookie tem path/domain incompatível\n";
        } else {
            echo "Cookies recebidos:\n\n";
            foreach ($_COOKIE as $name => $value) {
                if ($name === session_name()) {
                    echo "✓ $name = " . substr($value, 0, 26) . "... (SESSION COOKIE)\n";
                } else {
                    echo "  $name = " . (strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value) . "\n";
                }
            }
        }
        ?></pre>

        <h2>🔐 4. ESTADO DA SESSÃO</h2>
        <table>
            <tr>
                <th>Verificação</th>
                <th>Status</th>
                <th>Detalhes</th>
            </tr>
            <tr>
                <td>Sessão Iniciada</td>
                <td class="<?php echo session_status() === PHP_SESSION_ACTIVE ? 'ok' : 'error'; ?>">
                    <?php echo session_status() === PHP_SESSION_ACTIVE ? '✓ SIM' : '❌ NÃO'; ?>
                </td>
                <td><?php 
                    $status = session_status();
                    if ($status === PHP_SESSION_DISABLED) echo 'Sessões desabilitadas';
                    elseif ($status === PHP_SESSION_NONE) echo 'Sessão não iniciada';
                    elseif ($status === PHP_SESSION_ACTIVE) echo 'Sessão ativa';
                ?></td>
            </tr>
            <tr>
                <td>Cookie de Sessão Recebido</td>
                <td class="<?php echo isset($_COOKIE[session_name()]) ? 'ok' : 'error'; ?>">
                    <?php echo isset($_COOKIE[session_name()]) ? '✓ SIM' : '❌ NÃO'; ?>
                </td>
                <td><?php 
                    if (isset($_COOKIE[session_name()])) {
                        echo 'Cookie presente: ' . substr($_COOKIE[session_name()], 0, 20) . '...';
                    } else {
                        echo '⚠ NAVEGADOR NÃO ENVIOU COOKIE';
                    }
                ?></td>
            </tr>
            <?php if ($config_loaded): ?>
            <tr>
                <td>Usuário Logado (isLoggedIn)</td>
                <td class="<?php echo isLoggedIn() ? 'ok' : 'warn'; ?>">
                    <?php echo isLoggedIn() ? '✓ SIM' : '⚠ NÃO'; ?>
                </td>
                <td><?php 
                    if (isLoggedIn()) {
                        echo 'User ID: ' . ($_SESSION['user_id'] ?? 'N/A');
                    } else {
                        echo 'Não logado ou sessão vazia';
                    }
                ?></td>
            </tr>
            <?php endif; ?>
            <tr>
                <td>Variáveis $_SESSION</td>
                <td class="<?php echo !empty($_SESSION) ? 'info' : 'warn'; ?>">
                    <?php echo !empty($_SESSION) ? 'ℹ ' . count($_SESSION) . ' variáveis' : '⚠ Vazio'; ?>
                </td>
                <td><?php echo !empty($_SESSION) ? implode(', ', array_keys($_SESSION)) : 'Nenhuma variável de sessão'; ?></td>
            </tr>
        </table>

        <h2>📊 5. CONTEÚDO DA SESSÃO</h2>
        <pre><?php 
        if (empty($_SESSION)) {
            echo "⚠ SESSÃO VAZIA\n";
            echo "   A sessão está ativa mas não contém dados de usuário.\n";
            echo "   Isso indica que o usuário precisa fazer login novamente.\n";
        } else {
            print_r($_SESSION);
        }
        ?></pre>

        <?php if ($config_loaded && defined('BASE_PATH')): ?>
        <h2>⚙️ 6. CONFIGURAÇÕES DO CONFIG.PHP</h2>
        <table>
            <tr>
                <th>Constante</th>
                <th>Valor</th>
            </tr>
            <tr>
                <td>SITE_URL</td>
                <td><?php echo defined('SITE_URL') ? SITE_URL : 'N/A'; ?></td>
            </tr>
            <tr>
                <td>BASE_PATH</td>
                <td><?php echo defined('BASE_PATH') ? (BASE_PATH ?: '(vazio - raiz)') : 'N/A'; ?></td>
            </tr>
            <tr>
                <td>APP_ENV</td>
                <td><?php echo function_exists('env') ? env('APP_ENV', 'development') : 'N/A'; ?></td>
            </tr>
        </table>
        <?php endif; ?>

        <h2>🧪 7. TESTES E DIAGNÓSTICO</h2>
        
        <div class="instructions">
            <h3>📋 Como usar este debug:</h3>
            <ol>
                <li><strong class="ok">SE "Cookie de Sessão Recebido" = ❌ NÃO:</strong>
                    <ul>
                        <li>O problema é que o navegador não está enviando o cookie</li>
                        <li>Verifique o "Referer" acima - se estiver vazio, é link externo</li>
                        <li>Verifique "SameSite" - se for "Strict", MUDE para "Lax"</li>
                        <li>Verifique "Secure" - se HTTPS=SIM mas Secure=NÃO, há problema</li>
                    </ul>
                </li>
                <li><strong class="warn">SE "Cookie de Sessão Recebido" = ✓ SIM mas "Sessão Vazia":</strong>
                    <ul>
                        <li>O cookie foi enviado mas os dados da sessão foram perdidos</li>
                        <li>Possível causa: sessão expirou no servidor</li>
                        <li>Verifique "gc_maxlifetime" nas configurações PHP</li>
                    </ul>
                </li>
                <li><strong class="info">Para testar link externo:</strong>
                    <ul>
                        <li>1. Faça login normalmente</li>
                        <li>2. Copie o link de teste abaixo e envie para si no WhatsApp/Telegram</li>
                        <li>3. Clique no link vindo do WhatsApp</li>
                        <li>4. Veja se o cookie foi recebido</li>
                    </ul>
                </li>
            </ol>
        </div>

        <h3>🔗 Link de Teste (envie para WhatsApp e clique):</h3>
        <div style="background: #2a2a2a; padding: 15px; margin: 10px 0; border-radius: 5px;">
            <code id="test-link" style="color: #00ffff; word-break: break-all;">
                <?php 
                $test_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') 
                          . ($_SERVER['HTTP_HOST'] ?? 'localhost') 
                          . '/debug_session_full.php?test=external_link&t=' . time();
                echo htmlspecialchars($test_url);
                ?>
            </code>
            <button class="copy-btn" onclick="copyTestLink()">📋 Copiar Link</button>
        </div>

        <div style="margin-top: 20px;">
            <a href="index.php" class="test-link">🏠 Ir para Home</a>
            <?php if ($config_loaded && isLoggedIn()): ?>
                <a href="logout.php" class="test-link">🚪 Logout</a>
            <?php else: ?>
                <a href="login.php" class="test-link">🔑 Login</a>
            <?php endif; ?>
            <a href="debug_session_full.php" class="test-link">🔄 Recarregar Debug</a>
        </div>

        <div style="margin-top: 30px; padding: 15px; background: #2a2a2a; border-left: 5px solid #ff0000;">
            <h3 style="color: #ff0000;">⚠️ IMPORTANTE - SEGURANÇA</h3>
            <p style="color: #ffaa00;">
                Este arquivo expõe informações sensíveis sobre a sessão.<br>
                <strong>APAGUE este arquivo após o debug!</strong><br>
                Comando: <code style="background: #0a0a0a; padding: 3px 8px;">Remove-Item debug_session_full.php</code>
            </p>
        </div>
    </div>

    <script>
        function copyTestLink() {
            const link = document.getElementById('test-link').textContent.trim();
            navigator.clipboard.writeText(link).then(() => {
                alert('✓ Link copiado! Cole no WhatsApp/Telegram e clique nele.');
            }).catch(err => {
                alert('Erro ao copiar: ' + err);
            });
        }
    </script>
</body>
</html>
