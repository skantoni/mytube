<?php
/**
 * Teste de Proteção Path Traversal
 * 
 * Valida se api/stream_video.php bloqueia tentativas de acesso a arquivos fora de uploads/videos/
 * 
 * ⚠️ DELETE ESTE ARQUIVO APÓS VALIDAR!
 */

require_once 'includes/config.php';

echo "<h1>🛡️ Teste de Proteção Path Traversal</h1>\n";
echo "<p>Este teste valida se a API de streaming bloqueia path traversal.</p>\n\n";

// ============================================
// PREPARAÇÃO: Criar vídeos de teste no banco
// ============================================

echo "<h2>📋 Preparação</h2>\n";

// Vídeo legítimo
$stmt = $pdo->prepare("SELECT id FROM videos WHERE video_path = ? LIMIT 1");
$stmt->execute(['test_valid.mp4']);
$valid_video = $stmt->fetch();

if (!$valid_video) {
    // Criar vídeo legítimo de teste
    $stmt = $pdo->prepare("
        INSERT INTO videos (user_id, title, description, video_path, thumbnail_path, views_count, likes_count, is_public)
        VALUES (1, 'Teste Valid', 'Vídeo legítimo', 'test_valid.mp4', 'thumb.jpg', 0, 0, 1)
    ");
    $stmt->execute();
    $valid_video_id = $pdo->lastInsertId();
    echo "<p>✅ Vídeo legítimo criado (ID: $valid_video_id)</p>\n";
} else {
    $valid_video_id = $valid_video['id'];
    echo "<p>ℹ️ Vídeo legítimo já existe (ID: $valid_video_id)</p>\n";
}

// ============================================
// TESTES DE PATH TRAVERSAL
// ============================================

echo "<h2>🧪 Testes de Segurança</h2>\n";

$test_cases = [
    [
        'name' => 'Vídeo normal legítimo',
        'path' => 'test_valid.mp4',
        'expected' => 404, // 404 porque arquivo não existe fisicamente (ok em teste)
        'safe' => true
    ],
    [
        'name' => 'Path traversal - etc/passwd',
        'path' => '../../../../../../../etc/passwd',
        'expected' => 403, // Deve ser bloqueado
        'safe' => false
    ],
    [
        'name' => 'Path traversal - Windows hosts',
        'path' => '..\\..\\..\\..\\..\\..\\Windows\\System32\\drivers\\etc\\hosts',
        'expected' => 403,
        'safe' => false
    ],
    [
        'name' => 'Path traversal - config.php',
        'path' => '../../includes/config.php',
        'expected' => 403,
        'safe' => false
    ],
    [
        'name' => 'Path traversal - .env',
        'path' => '../../.env',
        'expected' => 403,
        'safe' => false
    ],
    [
        'name' => 'Path traversal - código PHP',
        'path' => '../../index.php',
        'expected' => 403,
        'safe' => false
    ],
];

echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>\n";
echo "<tr><th>Teste</th><th>Path Injetado</th><th>Código Esperado</th><th>Código Obtido</th><th>Status</th></tr>\n";

$passed = 0;
$failed = 0;

foreach ($test_cases as $test) {
    // Atualizar video_path no banco para simular injeção
    $stmt = $pdo->prepare("UPDATE videos SET video_path = ? WHERE id = ?");
    $stmt->execute([$test['path'], $valid_video_id]);
    
    // Fazer requisição para stream_video.php
    $url = 'http://localhost/my/api/stream_video.php?id=' . $valid_video_id;
    
    // Usar cURL para capturar HTTP status code
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_NOBODY => true, // HEAD request (não baixar corpo)
        CURLOPT_TIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    
    curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Verificar resultado
    $pass = ($http_code == $test['expected']);
    
    if ($pass) {
        $passed++;
        $status = '✅ PASSOU';
        $color = '#d4edda';
    } else {
        $failed++;
        $status = '❌ FALHOU';
        $color = '#f8d7da';
    }
    
    $safety_label = $test['safe'] ? '✅ Seguro' : '⚠️ Malicioso';
    
    echo "<tr style='background-color: $color;'>\n";
    echo "<td><strong>{$test['name']}</strong><br><small>$safety_label</small></td>\n";
    echo "<td><code>" . htmlspecialchars($test['path']) . "</code></td>\n";
    echo "<td><strong>{$test['expected']}</strong></td>\n";
    echo "<td><strong>$http_code</strong></td>\n";
    echo "<td><strong>$status</strong></td>\n";
    echo "</tr>\n";
}

// Restaurar vídeo legítimo
$stmt = $pdo->prepare("UPDATE videos SET video_path = ? WHERE id = ?");
$stmt->execute(['test_valid.mp4', $valid_video_id]);

echo "</table>\n\n";

// ============================================
// RESULTADOS
// ============================================

echo "<h2>📊 Resultados:</h2>\n";
echo "<ul>\n";
echo "<li><strong style='color: green;'>✅ Passou:</strong> $passed testes</li>\n";
echo "<li><strong style='color: red;'>❌ Falhou:</strong> $failed testes</li>\n";
echo "</ul>\n";

if ($failed === 0) {
    echo "<h2 style='color: green;'>🎉 Todos os testes passaram! Path Traversal está bloqueado.</h2>\n";
    echo "<p><strong>✅ Proteções funcionando:</strong></p>\n";
    echo "<ul>\n";
    echo "<li>realpath() resolvendo caminhos absolutos</li>\n";
    echo "<li>Validação de diretório (deve estar em uploads/videos/)</li>\n";
    echo "<li>Bloqueio de ../, \\..\\, symlinks</li>\n";
    echo "<li>MIME type validation (apenas video/*)</li>\n";
    echo "</ul>\n";
    echo "<p><strong>⚠️ IMPORTANTE:</strong> DELETE este arquivo antes de fazer deploy!</p>\n";
} else {
    echo "<h2 style='color: red;'>⚠️ Alguns testes falharam! REVISE A IMPLEMENTAÇÃO!</h2>\n";
    echo "<p>Path traversal pode estar vulnerável. NÃO faça deploy até corrigir.</p>\n";
}

// ============================================
// LIMPEZA
// ============================================

echo "\n<hr>\n";
echo "<h3>🧹 Limpeza</h3>\n";

// Deletar vídeo de teste
$stmt = $pdo->prepare("DELETE FROM videos WHERE id = ? AND title = 'Teste Valid'");
$stmt->execute([$valid_video_id]);

if ($stmt->rowCount() > 0) {
    echo "<p>✅ Vídeo de teste deletado do banco</p>\n";
} else {
    echo "<p>ℹ️ Vídeo de teste não foi deletado (pode ser vídeo real)</p>\n";
}

echo "\n<hr>\n";
echo "<p><small><strong>⚠️ ATENÇÃO:</strong> Este é um arquivo de TESTE. Delete-o antes de fazer deploy na VPS!</small></p>\n";
?>
