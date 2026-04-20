<?php
/**
 * Teste de Proteção Rate Limiting
 * 
 * Valida que login e reset de senha bloqueiam brute force
 * 
 * ⚠️ DELETE ESTE ARQUIVO APÓS VALIDAR!
 */

require_once 'includes/config.php';
require_once 'includes/rate_limit.php';

echo "<h1>🛡️ Teste de Proteção Rate Limiting</h1>\n";
echo "<p>Este teste valida proteção contra brute force.</p>\n\n";

// Limpar rate limits de teste anterior
$test_ip = '192.168.100.100';
$test_email = 'test@example.com';

try {
    $pdo->exec("DELETE FROM rate_limits WHERE identifier IN ('$test_ip', '$test_email', 'testuser')");
} catch (Exception $e) {
    // Tabela pode não existir ainda
}

echo "<h2>📋 Teste 1: Rate Limit - Login por IP</h2>\n";

$results = [];
for ($i = 1; $i <= 7; $i++) {
    rate_limit_record($pdo, 'login', $test_ip, false);
    $check = rate_limit_check($pdo, 'login', $test_ip, 5, 15);
    $results[] = [
        'attempt' => $i,
        'blocked' => $check['blocked'],
        'attempts' => $check['attempts'],
        'remaining' => $check['remaining']
    ];
}

echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>\n";
echo "<tr><th>Tentativa</th><th>Bloqueado</th><th>Tentativas</th><th>Restantes</th><th>Status</th></tr>\n";

$test1_pass = true;
foreach ($results as $r) {
    $expected_blocked = ($r['attempt'] >= 5);
    $is_correct = ($r['blocked'] === $expected_blocked);
    
    if (!$is_correct) $test1_pass = false;
    
    $color = $is_correct ? '#d4edda' : '#f8d7da';
    $status = $is_correct ? '✅ OK' : '❌ ERRO';
    
    echo "<tr style='background: $color;'>\n";
    echo "<td><strong>{$r['attempt']}</strong></td>\n";
    echo "<td>" . ($r['blocked'] ? '🔒 SIM' : '✅ NÃO') . "</td>\n";
    echo "<td>{$r['attempts']}</td>\n";
    echo "<td>{$r['remaining']}</td>\n";
    echo "<td><strong>$status</strong></td>\n";
    echo "</tr>\n";
}

echo "</table>\n\n";

if ($test1_pass) {
    echo "<p style='color: green;'><strong>✅ Teste 1 PASSOU:</strong> Bloqueio após 5 tentativas funcionando!</p>\n";
} else {
    echo "<p style='color: red;'><strong>❌ Teste 1 FALHOU:</strong> Rate limiting não está funcionando corretamente.</p>\n";
}

// Limpar para próximo teste
$pdo->exec("DELETE FROM rate_limits WHERE identifier = '$test_ip'");

echo "<hr>\n";
echo "<h2>📋 Teste 2: Rate Limit - Reset Code por Email</h2>\n";

$results2 = [];
for ($i = 1; $i <= 7; $i++) {
    rate_limit_record($pdo, 'reset_code_email', $test_email, false);
    $check = rate_limit_check($pdo, 'reset_code_email', $test_email, 5, 15);
    $results2[] = [
        'attempt' => $i,
        'blocked' => $check['blocked'],
        'attempts' => $check['attempts'],
        'remaining' => $check['remaining']
    ];
}

echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>\n";
echo "<tr><th>Tentativa</th><th>Bloqueado</th><th>Tentativas</th><th>Restantes</th><th>Status</th></tr>\n";

$test2_pass = true;
foreach ($results2 as $r) {
    $expected_blocked = ($r['attempt'] >= 5);
    $is_correct = ($r['blocked'] === $expected_blocked);
    
    if (!$is_correct) $test2_pass = false;
    
    $color = $is_correct ? '#d4edda' : '#f8d7da';
    $status = $is_correct ? '✅ OK' : '❌ ERRO';
    
    echo "<tr style='background: $color;'>\n";
    echo "<td><strong>{$r['attempt']}</strong></td>\n";
    echo "<td>" . ($r['blocked'] ? '🔒 SIM' : '✅ NÃO') . "</td>\n";
    echo "<td>{$r['attempts']}</td>\n";
    echo "<td>{$r['remaining']}</td>\n";
    echo "<td><strong>$status</strong></td>\n";
    echo "</tr>\n";
}

echo "</table>\n\n";

if ($test2_pass) {
    echo "<p style='color: green;'><strong>✅ Teste 2 PASSOU:</strong> Bloqueio após 5 tentativas funcionando!</p>\n";
} else {
    echo "<p style='color: red;'><strong>❌ Teste 2 FALHOU:</strong> Rate limiting não está funcionando corretamente.</p>\n";
}

echo "<hr>\n";
echo "<h2>📋 Teste 3: Limpar Rate Limit após Sucesso</h2>\n";

// Registrar 3 tentativas falhadas
for ($i = 1; $i <= 3; $i++) {
    rate_limit_record($pdo, 'login', $test_ip, false);
}

$before_success = rate_limit_check($pdo, 'login', $test_ip, 5, 15);

// Registrar sucesso (deve limpar tentativas)
rate_limit_record($pdo, 'login', $test_ip, true);

$after_success = rate_limit_check($pdo, 'login', $test_ip, 5, 15);

echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>\n";
echo "<tr><th>Momento</th><th>Bloqueado</th><th>Tentativas</th><th>Status</th></tr>\n";

$test3_pass = ($before_success['attempts'] === 3 && $after_success['attempts'] === 0);

echo "<tr style='background: #fff3cd;'>\n";
echo "<td>Antes do sucesso</td>\n";
echo "<td>" . ($before_success['blocked'] ? '🔒 SIM' : '✅ NÃO') . "</td>\n";
echo "<td>{$before_success['attempts']}</td>\n";
echo "<td>3 tentativas falhadas</td>\n";
echo "</tr>\n";

$color = $test3_pass ? '#d4edda' : '#f8d7da';
echo "<tr style='background: $color;'>\n";
echo "<td>Após sucesso</td>\n";
echo "<td>" . ($after_success['blocked'] ? '🔒 SIM' : '✅ NÃO') . "</td>\n";
echo "<td>{$after_success['attempts']}</td>\n";
echo "<td>" . ($test3_pass ? '✅ Limpou' : '❌ NÃO limpou') . "</td>\n";
echo "</tr>\n";

echo "</table>\n\n";

if ($test3_pass) {
    echo "<p style='color: green;'><strong>✅ Teste 3 PASSOU:</strong> Rate limit limpo após login bem-sucedido!</p>\n";
} else {
    echo "<p style='color: red;'><strong>❌ Teste 3 FALHOU:</strong> Rate limit não foi limpo.</p>\n";
}

// Limpar testes
$pdo->exec("DELETE FROM rate_limits WHERE identifier IN ('$test_ip', '$test_email')");

echo "<hr>\n";
echo "<h2>📊 Resultados Finais:</h2>\n";

$all_pass = ($test1_pass && $test2_pass && $test3_pass);

if ($all_pass) {
    echo "<h2 style='color: green;'>🎉 Todos os testes passaram!</h2>\n";
    echo "<p><strong>✅ Rate limiting funcionando corretamente:</strong></p>\n";
    echo "<ul>\n";
    echo "<li>Bloqueio após 5 tentativas</li>\n";
    echo "<li>Limpa tentativas após sucesso</li>\n";
    echo "<li>Previne brute force em login e reset de senha</li>\n";
    echo "</ul>\n";
    echo "<p><strong>⚠️ IMPORTANTE:</strong> DELETE este arquivo antes de fazer deploy!</p>\n";
} else {
    echo "<h2 style='color: red;'>⚠️ Alguns testes falharam!</h2>\n";
    echo "<p>Revise a implementação antes de fazer deploy.</p>\n";
}

echo "\n<hr>\n";
echo "<h3>🧪 Teste Manual Recomendado:</h3>\n";
echo "<ol>\n";
echo "<li>Abrir <a href='login.php' target='_blank'>login.php</a></li>\n";
echo "<li>Tentar fazer login 6 vezes com senha errada</li>\n";
echo "<li>Na 6ª tentativa, deve mostrar: <strong>\"Muitas tentativas. Tente novamente em 15 minutos\"</strong></li>\n";
echo "<li>Aguardar 15 minutos (ou apagar registros do banco) e testar login correto</li>\n";
echo "<li>Login deve funcionar normalmente</li>\n";
echo "</ol>\n";

echo "\n<hr>\n";
echo "<p><small><strong>⚠️ ATENÇÃO:</strong> Este é um arquivo de TESTE. Delete-o antes de fazer deploy na VPS!</small></p>\n";
?>
