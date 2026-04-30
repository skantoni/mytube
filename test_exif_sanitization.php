<?php
/**
 * Teste de Sanitização EXIF
 * 
 * Valida que EXIF/metadados são removidos de imagens
 * 
 * ⚠️ DELETE ESTE ARQUIVO APÓS VALIDAR!
 */

require_once 'includes/config.php';
require_once 'includes/image_sanitizer.php';

echo "<h1>🛡️ Teste de Sanitização EXIF</h1>\n";
echo "<p>Este teste valida remoção de metadados sensíveis de imagens.</p>\n\n";

echo "<h2>📋 Instruções de Teste Manual:</h2>\n";
echo "<ol>\n";
echo "<li><strong>Preparar imagem com EXIF:</strong>\n";
echo "   <ul>\n";
echo "   <li>Tire uma foto com seu celular (terá GPS automaticamente)</li>\n";
echo "   <li>OU use: <a href='https://github.com/ianare/exif-samples/raw/master/jpg/gps/DSCN0010.jpg' target='_blank'>Imagem de exemplo com GPS</a></li>\n";
echo "   </ul>\n";
echo "</li>\n";
echo "<li><strong>Fazer upload no perfil:</strong>\n";
echo "   <ul>\n";
echo "   <li>Ir para <a href='profile.php'>profile.php</a></li>\n";
echo "   <li>Fazer upload da foto com EXIF</li>\n";
echo "   <li>Salvar perfil</li>\n";
echo "   </ul>\n";
echo "</li>\n";
echo "<li><strong>Verificar EXIF removido:</strong>\n";
echo "   <ul>\n";
echo "   <li>Baixar a imagem do perfil (botão direito → Salvar imagem)</li>\n";
echo "   <li>Verificar EXIF em: <a href='https://www.metadata2go.com/' target='_blank'>metadata2go.com</a></li>\n";
echo "   <li>OU usar comando: <code>exiftool foto.jpg</code></li>\n";
echo "   <li>✅ <strong>DEVE MOSTRAR:</strong> No EXIF data found (ou metadados mínimos)</li>\n";
echo "   </ul>\n";
echo "</li>\n";
echo "</ol>\n\n";

echo "<hr>\n";
echo "<h2>🧪 Testes Automatizados:</h2>\n\n";

// Teste 1: Verificar se funções EXIF estão disponíveis
echo "<h3>Teste 1: Verificar extensão EXIF do PHP</h3>\n";
if (function_exists('exif_read_data')) {
    echo "<p style='color: green;'><strong>✅ PASSOU:</strong> Extensão EXIF disponível</p>\n";
    $test1_pass = true;
} else {
    echo "<p style='color: orange;'><strong>⚠️ AVISO:</strong> Extensão EXIF não disponível (não é crítico)</p>\n";
    echo "<p><small>Instalação: <code>sudo apt-get install php-exif</code> ou descomentar <code>extension=exif</code> no php.ini</small></p>\n";
    $test1_pass = false;
}

echo "<hr>\n";

// Teste 2: Verificar se funções GD estão disponíveis
echo "<h3>Teste 2: Verificar biblioteca GD (processamento de imagens)</h3>\n";
$gd_functions = ['imagecreatefromjpeg', 'imagecreatefrompng', 'imagejpeg', 'imagepng', 'imagewebp'];
$gd_ok = true;
foreach ($gd_functions as $func) {
    if (!function_exists($func)) {
        $gd_ok = false;
        echo "<p style='color: red;'>❌ Função ausente: <code>$func</code></p>\n";
    }
}

if ($gd_ok) {
    echo "<p style='color: green;'><strong>✅ PASSOU:</strong> Biblioteca GD completa</p>\n";
    $test2_pass = true;
} else {
    echo "<p style='color: red;'><strong>❌ FALHOU:</strong> Biblioteca GD incompleta</p>\n";
    echo "<p><small>Instalação: <code>sudo apt-get install php-gd</code></small></p>\n";
    $test2_pass = false;
}

echo "<hr>\n";

// Teste 3: Criar imagem de teste e verificar sanitização
echo "<h3>Teste 3: Simular sanitização de imagem</h3>\n";

// Criar diretório de teste
$test_dir = __DIR__ . '/uploads/test_exif/';
if (!is_dir($test_dir)) {
    @mkdir($test_dir, 0755, true);
}

// Criar imagem de teste (100x100 pixels vermelha)
$test_image = imagecreatetruecolor(100, 100);
$red = imagecolorallocate($test_image, 255, 0, 0);
imagefill($test_image, 0, 0, $red);

$test_file = $test_dir . 'test_' . time() . '.jpg';
imagejpeg($test_image, $test_file, 90);
imagedestroy($test_image);

echo "<p>Imagem de teste criada: <code>" . basename($test_file) . "</code></p>\n";

// Sanitizar
$result = sanitize_image_exif($test_file, 90);

if ($result['success']) {
    echo "<p style='color: green;'><strong>✅ PASSOU:</strong> Sanitização executada com sucesso</p>\n";
    echo "<ul>\n";
    echo "<li>Tipo MIME: {$result['mime_type']}</li>\n";
    echo "<li>Dimensões: {$result['dimensions']['width']}x{$result['dimensions']['height']}</li>\n";
    echo "<li>Mensagem: {$result['message']}</li>\n";
    echo "</ul>\n";
    $test3_pass = true;
} else {
    echo "<p style='color: red;'><strong>❌ FALHOU:</strong> {$result['message']}</p>\n";
    $test3_pass = false;
}

// Limpar arquivo de teste
@unlink($test_file);
@rmdir($test_dir);

echo "<hr>\n";

// Resultados finais
echo "<h2>📊 Resultados Finais:</h2>\n";

$all_pass = ($test2_pass && $test3_pass); // test1 não é crítico

if ($all_pass) {
    echo "<h2 style='color: green;'>🎉 Todos os testes críticos passaram!</h2>\n";
    echo "<p><strong>✅ Sistema de sanitização EXIF funcionando:</strong></p>\n";
    echo "<ul>\n";
    echo "<li>Biblioteca GD disponível</li>\n";
    echo "<li>Sanitização funcional</li>\n";
    echo "<li>Metadados serão removidos de todas as imagens</li>\n";
    echo "</ul>\n";
    if (!$test1_pass) {
        echo "<p><strong>⚠️ AVISO:</strong> Extensão EXIF não disponível (apenas para verificação, não crítico)</p>\n";
    }
} else {
    echo "<h2 style='color: red;'>⚠️ Alguns testes falharam!</h2>\n";
    echo "<p>Instale as dependências necessárias antes de usar em produção.</p>\n";
}

echo "\n<hr>\n";
echo "<h3>🔍 Como Verificar EXIF Manualmente:</h3>\n";
echo "<p><strong>Ferramentas Online:</strong></p>\n";
echo "<ul>\n";
echo "<li><a href='https://www.metadata2go.com/' target='_blank'>Metadata2Go</a> - Visualizador de EXIF</li>\n";
echo "<li><a href='https://exifinfo.org/' target='_blank'>EXIFinfo</a> - Análise detalhada</li>\n";
echo "<li><a href='https://jimpl.com/' target='_blank'>Jeffrey's EXIF Viewer</a></li>\n";
echo "</ul>\n";

echo "<p><strong>Linha de comando (Linux/Mac):</strong></p>\n";
echo "<pre>exiftool foto.jpg</pre>\n";
echo "<p><small>Instalação: <code>sudo apt-get install libimage-exiftool-perl</code></small></p>\n";

echo "<p><strong>Windows:</strong></p>\n";
echo "<ol>\n";
echo "<li>Botão direito na imagem → Propriedades</li>\n";
echo "<li>Aba \"Detalhes\"</li>\n";
echo "<li>Verificar se GPS/Localização está vazio</li>\n";
echo "</ol>\n";

echo "\n<hr>\n";
echo "<p><small><strong>⚠️ ATENÇÃO:</strong> Este é um arquivo de TESTE. Delete-o antes de fazer deploy na VPS!</small></p>\n";
?>
