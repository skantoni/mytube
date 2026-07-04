<?php
/**
 * includes/whatsapp_helper.php
 *
 * Funções utilitárias para enviar mensagens via o bot WhatsApp local (Baileys).
 *
 * Uso:
 *   require_once __DIR__ . '/whatsapp_helper.php';
 *   $ok = sendWhatsappMessage('244912345678', 'Texto da mensagem');
 */

/**
 * Envia uma mensagem de texto para um número via bot WhatsApp local.
 *
 * @param  string $phone   Número de telemóvel (ex: "244912345678" ou "+244912345678" ou "912345678")
 * @param  string $message Texto a enviar
 * @return bool            true em caso de sucesso, false em caso de falha
 */
function sendWhatsappMessage(string $phone, string $message): bool
{
    $botUrl = 'http://127.0.0.1:3002/send-message';

    $payload = json_encode([
        'phone'   => normalizeWhatsappNumber($phone),
        'message' => $message,
    ]);

    $ch = curl_init($botUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 10,            // 10 segundos máximo
        CURLOPT_CONNECTTIMEOUT => 3,             // 3 segundos para conectar
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log("[WhatsApp] cURL error: {$curlErr}");
        return false;
    }

    if ($httpCode !== 200) {
        error_log("[WhatsApp] HTTP {$httpCode}: {$response}");
        return false;
    }

    $data = json_decode($response, true);
    $ok   = (bool)($data['success'] ?? false);

    if (!$ok) {
        error_log("[WhatsApp] Bot error: " . ($data['message'] ?? 'unknown'));
    }

    return $ok;
}

/**
 * Verifica se o bot WhatsApp local está online.
 *
 * @return bool
 */
function isWhatsappBotOnline(): bool
{
    $ch = curl_init('http://127.0.0.1:3002/status');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) return false;

    $data = json_decode($response, true);
    return (bool)($data['connected'] ?? false);
}

/**
 * Normaliza um número para o formato internacional angolano.
 * - "912345678"       → "244912345678"
 * - "+244912345678"   → "244912345678"
 * - "244912345678"    → "244912345678"
 *
 * @param  string $phone
 * @return string
 */
function normalizeWhatsappNumber(string $phone): string
{
    // Remove tudo que não seja dígito
    $digits = preg_replace('/\D/', '', $phone);

    // Número angolano com 9 dígitos → adiciona 244
    if (strlen($digits) === 9) {
        return '244' . $digits;
    }

    return $digits;
}

/**
 * Valida se um número parece ser um número de telemóvel angolano válido.
 * Prefixos comuns: 9xx (Unitel, Movicel, Africell)
 *
 * @param  string $phone
 * @return bool
 */
function isValidAngolanPhone(string $phone): bool
{
    $digits = preg_replace('/\D/', '', $phone);

    // Com prefixo 244: 12 dígitos começando com 2449xx
    if (strlen($digits) === 12 && str_starts_with($digits, '244')) {
        $local = substr($digits, 3);
        return preg_match('/^9[0-9]{8}$/', $local) === 1;
    }

    // Sem prefixo: 9 dígitos começando com 9
    if (strlen($digits) === 9) {
        return preg_match('/^9[0-9]{8}$/', $digits) === 1;
    }

    return false;
}
