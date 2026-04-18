<?php
/**
 * Environment Variables Loader
 * Carrega variáveis de ambiente do arquivo .env
 */

function loadEnv($path = __DIR__ . '/../.env') {
    if (!file_exists($path)) {
        // Em desenvolvimento, tenta .env.example
        $examplePath = __DIR__ . '/../.env.example';
        if (file_exists($examplePath)) {
            error_log("AVISO: Arquivo .env não encontrado. Usando .env.example como fallback.");
            error_log("AÇÃO NECESSÁRIA: Copie .env.example para .env e configure suas credenciais!");
            $path = $examplePath;
        } else {
            throw new Exception(".env file not found at: $path");
        }
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Ignora comentários
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        // Parse linha KEY=VALUE
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Remove aspas se existirem
            if (preg_match('/^(["\'])(.*)\\1$/', $value, $matches)) {
                $value = $matches[2];
            }

            // Define variável de ambiente se ainda não estiver definida
            if (!getenv($key)) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
}

/**
 * Helper para obter variável de ambiente com valor padrão
 */
function env($key, $default = null) {
    $value = getenv($key);
    if ($value === false) {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }
    return $value;
}

// Carrega automaticamente quando este arquivo é incluído
try {
    loadEnv();
} catch (Exception $e) {
    die("ERRO CRÍTICO: Não foi possível carregar configurações. " . $e->getMessage());
}
