<?php
require_once 'includes/config.php';
require_once 'includes/upload_validation.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$test_results = [];

// Teste 1: Validar imagem legítima
if (isset($_FILES['test_image']) && $_FILES['test_image']['error'] === UPLOAD_ERR_OK) {
    $validation = validate_image_upload(
        $_FILES['test_image']['tmp_name'],
        $_FILES['test_image']['name']
    );
    $test_results['image'] = $validation;
}

// Teste 2: Validar vídeo legítimo
if (isset($_FILES['test_video']) && $_FILES['test_video']['error'] === UPLOAD_ERR_OK) {
    $validation = validate_video_upload(
        $_FILES['test_video']['tmp_name'],
        $_FILES['test_video']['name']
    );
    $test_results['video'] = $validation;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teste de Validação de Upload</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #3b82f6;
            padding-bottom: 10px;
        }
        .test-section {
            margin: 30px 0;
            padding: 20px;
            background: #f9fafb;
            border-radius: 4px;
            border-left: 4px solid #3b82f6;
        }
        .result {
            margin-top: 15px;
            padding: 15px;
            border-radius: 4px;
        }
        .result.success {
            background: #d1fae5;
            border: 1px solid #10b981;
            color: #065f46;
        }
        .result.error {
            background: #fee2e2;
            border: 1px solid #ef4444;
            color: #991b1b;
        }
        input[type="file"] {
            display: block;
            margin: 10px 0;
            padding: 10px;
            border: 2px dashed #cbd5e1;
            border-radius: 4px;
            width: 100%;
        }
        button {
            background: #3b82f6;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            margin-top: 10px;
        }
        button:hover {
            background: #2563eb;
        }
        .info {
            background: #dbeafe;
            border: 1px solid #3b82f6;
            color: #1e40af;
            padding: 15px;
            border-radius: 4px;
            margin: 20px 0;
        }
        pre {
            background: #1e293b;
            color: #e2e8f0;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔒 Teste de Validação MIME de Uploads</h1>
        
        <div class="info">
            <strong>📌 Como testar:</strong><br>
            1. Faça upload de uma imagem/vídeo <strong>legítimo</strong> → deve passar ✅<br>
            2. Renomeie um arquivo .txt para .jpg e tente enviar → deve ser rejeitado ❌<br>
            3. Tente enviar um arquivo .php.jpg → deve ser rejeitado ❌
        </div>

        <div class="test-section">
            <h2>Teste 1: Validação de Imagem</h2>
            <form method="POST" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <label>Selecione uma imagem (JPG, PNG, GIF, WEBP):</label>
                <input type="file" name="test_image" accept="image/*" required>
                <button type="submit">Testar Validação</button>
            </form>

            <?php if (isset($test_results['image'])): ?>
                <div class="result <?php echo $test_results['image']['valid'] ? 'success' : 'error'; ?>">
                    <?php if ($test_results['image']['valid']): ?>
                        <strong>✅ VÁLIDO</strong><br>
                        MIME Type: <?php echo htmlspecialchars($test_results['image']['mime']); ?><br>
                        Extensão: <?php echo htmlspecialchars($test_results['image']['extension']); ?>
                    <?php else: ?>
                        <strong>❌ REJEITADO</strong><br>
                        Erro: <?php echo htmlspecialchars($test_results['image']['error']); ?><br>
                        <?php if ($test_results['image']['mime']): ?>
                            MIME Type detectado: <?php echo htmlspecialchars($test_results['image']['mime']); ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="test-section">
            <h2>Teste 2: Validação de Vídeo</h2>
            <form method="POST" enctype="multipart/form-data">
                <?php echo csrf_field(); ?>
                <label>Selecione um vídeo (MP4, AVI, MOV, WEBM):</label>
                <input type="file" name="test_video" accept="video/*" required>
                <button type="submit">Testar Validação</button>
            </form>

            <?php if (isset($test_results['video'])): ?>
                <div class="result <?php echo $test_results['video']['valid'] ? 'success' : 'error'; ?>">
                    <?php if ($test_results['video']['valid']): ?>
                        <strong>✅ VÁLIDO</strong><br>
                        MIME Type: <?php echo htmlspecialchars($test_results['video']['mime']); ?><br>
                        Extensão: <?php echo htmlspecialchars($test_results['video']['extension']); ?>
                    <?php else: ?>
                        <strong>❌ REJEITADO</strong><br>
                        Erro: <?php echo htmlspecialchars($test_results['video']['error']); ?><br>
                        <?php if ($test_results['video']['mime']): ?>
                            MIME Type detectado: <?php echo htmlspecialchars($test_results['video']['mime']); ?>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="info">
            <strong>🧪 Exemplos de testes:</strong>
            <pre><?php echo htmlspecialchars(<<<'EXAMPLES'
# Criar arquivo malicioso de teste:
echo "<?php system($_GET['cmd']); ?>" > malware.php

# Renomear para enganar validação antiga:
mv malware.php malware.jpg

# Tentar fazer upload de malware.jpg
# ❌ Deve ser rejeitado: "Tipo de arquivo não permitido"
# MIME detectado será: "text/x-php" (não "image/jpeg")
EXAMPLES); ?></pre>
        </div>

        <p><a href="index.php">← Voltar ao Feed</a></p>
    </div>
</body>
</html>
