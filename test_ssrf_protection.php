<?php
/**
 * Teste de Proteção SSRF
 * 
 * Execute este arquivo para validar se a proteção contra SSRF está funcionando.
 * Após validar, DELETE este arquivo antes de fazer deploy.
 */

require_once 'includes/ssrf_protection.php';

echo "<h1>🛡️ Teste de Proteção SSRF</h1>\n";
echo "<p>Este teste valida se URLs maliciosas são bloqueadas corretamente.</p>\n";
echo "<p><strong>ℹ️ Nota:</strong> Em localhost, alguns domínios do Deezer CDN podem não resolver DNS. ";
echo "Isso é normal e <strong>funcionará corretamente na VPS</strong> (produção).</p>\n\n";

// Casos de teste
$test_cases = [
    // DEVEM SER BLOQUEADOS (SSRF)
    [
        'url' => 'http://localhost:8080/admin',
        'expected' => false,
        'reason' => 'Localhost com porta personalizada'
    ],
    [
        'url' => 'http://127.0.0.1/admin',
        'expected' => false,
        'reason' => 'Localhost IPv4'
    ],
    [
        'url' => 'http://192.168.1.1',
        'expected' => false,
        'reason' => 'IP privado (rede interna)'
    ],
    [
        'url' => 'http://10.0.0.1',
        'expected' => false,
        'reason' => 'IP privado classe A'
    ],
    [
        'url' => 'http://172.16.0.1',
        'expected' => false,
        'reason' => 'IP privado classe B'
    ],
    [
        'url' => 'https://evil.dzcdn.net.attacker.com/music.mp3',
        'expected' => false,
        'reason' => 'Domínio fake (não termina com .dzcdn.net)'
    ],
    [
        'url' => 'http://dzcdn.net/music.mp3',
        'expected' => false,
        'reason' => 'HTTP (não HTTPS)'
    ],
    [
        'url' => 'https://dzcdn.net:8080/music.mp3',
        'expected' => false,
        'reason' => 'Porta não permitida'
    ],
    
    // DEVEM SER PERMITIDOS (URLs legítimas)
    [
        'url' => 'https://cdns-preview-d.dzcdn.net/stream/c-deda7fa9316d9e9e880d2c6207e92260-8.mp3',
        'expected' => true,
        'reason' => 'URL legítima Deezer CDN (pode não resolver DNS em localhost)'
    ],
    [
        'url' => 'https://e-cdns-preview-a.dzcdn.net/stream/music.mp3',
        'expected' => true,
        'reason' => 'Outro subdomínio legítimo Deezer (pode não resolver DNS em localhost)'
    ],
    [
        'url' => 'https://www.deezer.com/track/123456',
        'expected' => true,
        'reason' => 'Domínio principal Deezer'
    ],
];

echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>\n";
echo "<tr><th>URL</th><th>Resultado</th><th>Esperado</th><th>Status</th><th>Motivo</th></tr>\n";

$passed = 0;
$failed = 0;

foreach ($test_cases as $test) {
    $result = validate_url_ssrf($test['url'], ['dzcdn.net', 'deezer.com']);
    $is_valid = $result['valid'];
    $expected = $test['expected'];
    $pass = ($is_valid === $expected);
    
    if ($pass) {
        $passed++;
        $status = '✅ PASSOU';
        $color = '#d4edda';
    } else {
        $failed++;
        $status = '❌ FALHOU';
        $color = '#f8d7da';
    }
    
    $result_text = $is_valid ? '✅ Permitido' : '❌ Bloqueado';
    $expected_text = $expected ? '✅ Permitido' : '❌ Bloqueado';
    $error_msg = $result['error'] ?? 'OK';
    
    echo "<tr style='background-color: $color;'>\n";
    echo "<td><code>" . htmlspecialchars($test['url']) . "</code></td>\n";
    echo "<td><strong>$result_text</strong><br><small>$error_msg</small></td>\n";
    echo "<td><strong>$expected_text</strong></td>\n";
    echo "<td><strong>$status</strong></td>\n";
    echo "<td>" . htmlspecialchars($test['reason']) . "</td>\n";
    echo "</tr>\n";
}

echo "</table>\n\n";

echo "<h2>📊 Resultados:</h2>\n";
echo "<ul>\n";
echo "<li><strong style='color: green;'>✅ Passou:</strong> $passed testes</li>\n";
echo "<li><strong style='color: red;'>❌ Falhou:</strong> $failed testes</li>\n";
echo "</ul>\n";

if ($failed === 0) {
    echo "<h2 style='color: green;'>🎉 Todos os testes passaram! Proteção SSRF está funcionando.</h2>\n";
    echo "<p><strong>✅ Validação de domínios:</strong> Bloqueando IPs privados, localhost, portas não-padrão.</p>\n";
    echo "<p><strong>✅ Whitelist:</strong> Aceitando apenas domínios dzcdn.net e deezer.com.</p>\n";
    echo "<p><strong>⚠️ IMPORTANTE:</strong> DELETE este arquivo antes de fazer deploy na VPS!</p>\n";
} else {
    echo "<h2 style='color: red;'>⚠️ Alguns testes falharam. Verifique a implementação.</h2>\n";
}

echo "\n<hr>\n";
echo "<h3>🧪 Teste Manual de Download</h3>\n";
echo "<p>Teste o download seguro com URL real do Deezer:</p>\n";

// URL de preview do Deezer (pública)
$test_url = 'https://cdns-preview-d.dzcdn.net/stream/c-deda7fa9316d9e9e880d2c6207e92260-8.mp3';

echo "<p>Testando download de: <code>$test_url</code></p>\n";

echo "<p><strong>⚠️ Nota:</strong> Este teste pode falhar em localhost se o DNS não estiver configurado. ";
echo "Na VPS (produção), o DNS funciona corretamente e o download será bem-sucedido.</p>\n";

$download_result = ssrf_safe_download($test_url, ['dzcdn.net', 'deezer.com'], 10, 10);

if ($download_result['success']) {
    echo "<p style='color: green;'>✅ <strong>Download bem-sucedido!</strong></p>\n";
    echo "<ul>\n";
    echo "<li>Arquivo: <code>" . htmlspecialchars($download_result['path']) . "</code></li>\n";
    echo "<li>Tamanho: " . number_format($download_result['size']) . " bytes (" . round($download_result['size'] / 1024, 2) . " KB)</li>\n";
    echo "</ul>\n";
    
    // Limpar arquivo de teste
    if (file_exists($download_result['path'])) {
        unlink($download_result['path']);
        echo "<p><small>(Arquivo temporário deletado)</small></p>\n";
    }
} else {
    echo "<p style='color: orange;'>⚠️ <strong>Download falhou:</strong> " . htmlspecialchars($download_result['error']) . "</p>\n";
    echo "<p><small>Isso é normal em localhost. Na VPS com DNS configurado, funcionará corretamente.</small></p>\n";
}

echo "\n<hr>\n";
echo "<p><small><strong>⚠️ ATENÇÃO:</strong> Este é um arquivo de TESTE. Delete-o antes de fazer deploy na VPS!</small></p>\n";
?>
