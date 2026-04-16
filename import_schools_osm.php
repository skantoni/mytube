<?php
/**
 * Importar escolas de Luanda e Icolo e Bengo do OpenStreetMap (Overpass API)
 * 
 * Fonte: OpenStreetMap via Overpass API (gratuito, sem chave API)
 * Dados: amenity=school|college|university com nome
 * 
 * Uso: php import_schools_osm.php [--dry-run] [--clear-old]
 *   --dry-run   Mostra o que seria importado sem inserir no banco
 *   --clear-old Limpa escolas existentes (CUIDADO: só usar na primeira vez)
 */

require_once __DIR__ . '/includes/config.php';

// ── Parâmetros CLI ──────────────────────────────────────────
$dryRun   = in_array('--dry-run', $argv ?? []);
$clearOld = in_array('--clear-old', $argv ?? []);

echo "=== Importação de Escolas via OpenStreetMap ===\n";
echo "Modo: " . ($dryRun ? "DRY-RUN (sem gravar)" : "INSERÇÃO REAL") . "\n\n";

// ── Áreas de busca (bounding box: sul, oeste, norte, leste) ──
$areas = [
    [
        'name'     => 'Luanda (cidade)',
        'city'     => 'Luanda',
        'province' => 'Luanda',
        'bbox'     => '-9.05,13.05,-8.70,13.55',
    ],
    [
        'name'     => 'Icolo e Bengo',
        'city'     => 'Icolo e Bengo',
        'province' => 'Bengo',
        'bbox'     => '-9.30,13.55,-8.70,14.10',
    ],
    [
        'name'     => 'Viana / Cacuaco / Belas',
        'city'     => 'Luanda',
        'province' => 'Luanda',
        'bbox'     => '-9.15,13.25,-8.85,13.55',
    ],
    [
        'name'     => 'Zango / Kilamba Kiaxi',
        'city'     => 'Luanda',
        'province' => 'Luanda',
        'bbox'     => '-9.00,13.20,-8.82,13.38',
    ],
    [
        'name'     => 'Cazenga / Rangel / Sambizanga',
        'city'     => 'Luanda',
        'province' => 'Luanda',
        'bbox'     => '-8.87,13.22,-8.80,13.32',
    ],
];

$overpassUrl = 'https://overpass-api.de/api/interpreter';
$overpassMirrors = [
    'https://overpass-api.de/api/interpreter',
    'https://overpass.kumi.systems/api/interpreter',
];

/**
 * Consultar Overpass API para escolas numa bounding box
 */
function fetchSchoolsFromOSM(string $bbox, array $mirrors): array {
    // Query Overpass: escolas, colégios e universidades COM nome
    $query = <<<OQL
[out:json][timeout:90];
(
  node["amenity"~"^(school|college|university)$"]["name"](${bbox});
  way["amenity"~"^(school|college|university)$"]["name"](${bbox});
  relation["amenity"~"^(school|college|university)$"]["name"](${bbox});
);
out center;
OQL;

    foreach ($mirrors as $baseUrl) {
        $url = $baseUrl . '?data=' . urlencode($query);
        
        $context = stream_context_create([
            'http' => [
                'timeout' => 120,
                'header'  => "User-Agent: MyTube-SchoolImport/1.0\r\n",
            ],
        ]);
        
        echo "  Consultando " . parse_url($baseUrl, PHP_URL_HOST) . "...\n";
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            echo "  AVISO: Falha neste mirror, tentando próximo...\n";
            continue;
        }
        
        $data = json_decode($response, true);
        if (!$data || !isset($data['elements'])) {
            echo "  AVISO: Resposta inválida, tentando próximo...\n";
            continue;
        }
        
        return $data['elements'];
    }
    
    echo "  ERRO: Todos os mirrors falharam\n";
    return [];
}

/**
 * Limpar e padronizar o nome da escola
 */
function cleanSchoolName(string $name): string {
    $name = trim($name);
    
    // Remover prefixos comuns redundantes
    // Ex: "Escola Primária n.º 1234" → manter como está
    // Ex: "  Escola  ABC  " → "Escola ABC"
    $name = preg_replace('/\s+/', ' ', $name);
    
    // Capitalizar corretamente (Title Case para português)
    // Não alterar se já parece formatado
    if ($name === mb_strtoupper($name)) {
        // Se está todo em maiúsculas, converter para Title Case
        $name = mb_convert_case($name, MB_CASE_TITLE);
        
        // Corrigir preposições em português (de, da, do, das, dos, e, a, o)
        $name = preg_replace_callback('/\b(D[aeou]s?|E|A|O|N[ao]s?)\b/u', function ($m) {
            $lower = mb_strtolower($m[0]);
            // Não converter se for a primeira palavra
            return $lower;
        }, $name);
        
        // Garantir que a primeira letra é maiúscula
        $name = mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
    }
    
    return $name;
}

/**
 * Gerar short_name a partir do nome
 */
function generateShortName(string $name): string {
    // Se tem sigla entre parênteses, usar
    if (preg_match('/\(([A-Z]{2,10})\)/', $name, $m)) {
        return $m[1];
    }
    
    // Remover palavras comuns para gerar sigla
    $ignore = ['de', 'da', 'do', 'das', 'dos', 'e', 'a', 'o', 'na', 'no', 'nas', 'nos',
               'escola', 'colégio', 'complexo', 'escolar', 'privado', 'privada',
               'instituto', 'técnico', 'técnica', 'primária', 'primário',
               'secundária', 'secundário', 'n.º', 'nº', 'lda'];
    
    $words = preg_split('/[\s\-]+/', $name);
    $words = array_filter($words, fn($w) => !in_array(mb_strtolower($w), $ignore) && mb_strlen($w) > 1);
    
    if (count($words) === 0) {
        return mb_substr($name, 0, 6);
    }
    
    if (count($words) === 1) {
        return mb_strtoupper(mb_substr(reset($words), 0, 6));
    }
    
    // Pegar iniciais de cada palavra significativa
    $initials = '';
    foreach ($words as $w) {
        $initials .= mb_strtoupper(mb_substr($w, 0, 1));
        if (mb_strlen($initials) >= 6) break;
    }
    
    return $initials;
}

/**
 * Resolver tipo (amenity) para label legível
 */
function amenityLabel(string $amenity): string {
    return match($amenity) {
        'university' => 'Universidade',
        'college'    => 'Instituto/Colégio',
        default      => 'Escola',
    };
}

/**
 * Verificar se a escola deve ser ignorada (nomes genéricos, só números, creches)
 */
function shouldSkipSchool(string $name): bool {
    $lower = mb_strtolower(trim($name));
    
    // Nomes que são só números (ex: "5032", "3105")
    if (preg_match('/^\d+$/', $lower)) return true;
    
    // Nomes genéricos demais
    $generic = ['escola', 'escola primária', 'escola primaria', 'creche', 'school', 'college'];
    if (in_array($lower, $generic)) return true;
    
    // Creches (normalmente não interessam para o ranking)
    if (preg_match('/^creche\b/i', $lower)) return true;
    
    // Nomes muito curtos (< 3 caracteres) provavelmente são lixo
    if (mb_strlen($lower) < 3) return true;
    
    // Escolas com nomes tipo "5032 Baia", "5010 Baia" (só número + baia/bloco)
    if (preg_match('/^\d+\s*(baia|bloco)$/i', $lower)) return true;
    
    // Nomes tipo "Escola Bloco A", "Escola Bloco D" (sem identificação real)
    if (preg_match('/^escola\s+bloco\s+[a-z]$/i', $lower)) return true;
    
    // Escola com nome tipo "Escola Bloco X" (genérico)
    if (preg_match('/^escola\s+(do\s+)?bloco\s+/i', $lower)) return true;
    
    return false;
}

// ── Buscar escolas existentes para evitar duplicados ──────
$existing = $pdo->query("SELECT LOWER(name) as lname FROM schools")->fetchAll(PDO::FETCH_COLUMN);
$existingSet = array_flip($existing);

echo "Escolas já no banco: " . count($existingSet) . "\n\n";

// ── Processar cada área ──────────────────────────────────
$allSchools  = [];
$duplicates  = 0;
$unnamed     = 0;

foreach ($areas as $area) {
    echo "── {$area['name']} (bbox: {$area['bbox']}) ──\n";
    
    $elements = fetchSchoolsFromOSM($area['bbox'], $overpassMirrors);
    echo "  Elementos encontrados: " . count($elements) . "\n";
    
    foreach ($elements as $el) {
        $tags = $el['tags'] ?? [];
        $name = $tags['name'] ?? ($tags['name:pt'] ?? '');
        
        if (empty($name)) {
            $unnamed++;
            continue;
        }
        
        $name = cleanSchoolName($name);
        $lowerName = mb_strtolower($name);
        
        // Filtrar entradas inúteis
        if (shouldSkipSchool($name)) {
            $unnamed++;
            continue;
        }
        
        // Ignorar se já existe no banco
        if (isset($existingSet[$lowerName])) {
            echo "  [SKIP] Já existe: {$name}\n";
            $duplicates++;
            continue;
        }
        
        // Ignorar se já foi processado nesta execução
        if (isset($allSchools[$lowerName])) {
            continue;
        }
        
        $amenity = $tags['amenity'] ?? 'school';
        $shortName = generateShortName($name);
        
        $allSchools[$lowerName] = [
            'name'       => $name,
            'short_name' => $shortName,
            'city'       => $tags['addr:city'] ?? $area['city'],
            'province'   => $area['province'],
            'amenity'    => $amenity,
            'osm_id'     => $el['id'],
        ];
    }
    
    echo "\n";
    
    // Esperar 2 segundos entre requests (respeitar rate limit da Overpass API)
    if (next($areas) !== false) {
        echo "  Aguardando 2s (rate limit)...\n";
        sleep(2);
    }
}

echo "=== Resumo ===\n";
echo "Novas escolas encontradas: " . count($allSchools) . "\n";
echo "Duplicados ignorados: {$duplicates}\n";
echo "Sem nome (ignorados): {$unnamed}\n\n";

if (empty($allSchools)) {
    echo "Nenhuma escola nova para importar.\n";
    exit(0);
}

// ── Listar escolas a importar ──────────────────────────
echo "── Escolas a importar ──\n";
$i = 1;
foreach ($allSchools as $school) {
    $type = amenityLabel($school['amenity']);
    echo sprintf("  %3d. [%s] %s (%s) — %s, %s\n",
        $i++, $school['short_name'], $school['name'], $type, $school['city'], $school['province']);
}
echo "\n";

if ($dryRun) {
    echo "=== DRY-RUN: Nenhuma alteração feita ===\n";
    exit(0);
}

// ── Inserir no banco ──────────────────────────────────
$inserted = 0;
$errors   = 0;

$stmt = $pdo->prepare("
    INSERT INTO schools (name, short_name, city, province, is_active)
    VALUES (?, ?, ?, ?, 1)
    ON DUPLICATE KEY UPDATE name = name
");

foreach ($allSchools as $school) {
    try {
        $stmt->execute([
            $school['name'],
            $school['short_name'],
            $school['city'],
            $school['province'],
        ]);
        
        if ($stmt->rowCount() > 0) {
            $inserted++;
            echo "  ✓ {$school['name']}\n";
        }
    } catch (PDOException $e) {
        $errors++;
        echo "  ✗ ERRO ao inserir '{$school['name']}': " . $e->getMessage() . "\n";
    }
}

echo "\n=== Importação concluída ===\n";
echo "Inseridas: {$inserted}\n";
echo "Erros: {$errors}\n";

$total = $pdo->query("SELECT COUNT(*) FROM schools WHERE is_active = 1")->fetchColumn();
echo "Total de escolas no banco: {$total}\n";
