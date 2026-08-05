<?php
/**
 * livekit_jwt.php — Gerador de Tokens JWT para o LiveKit
 * Puro PHP, sem Composer. Implementa o formato exato que o LiveKit espera.
 *
 * Uso:
 *   $token = livekit_generate_token('room_123', 'user_456', 'João', true, false);
 *   // publisher: canPublish=true,  canSubscribe=false
 *   // subscriber: canPublish=false, canSubscribe=true
 */

/**
 * Gera um token JWT assinado para o LiveKit.
 *
 * @param string $roomName     Nome único da sala (ex: "stream_42")
 * @param string $identity     Identificador único do participante (ex: "user_7")
 * @param string $displayName  Nome visível do participante
 * @param bool   $canPublish   Pode enviar vídeo/áudio? (true = streamer)
 * @param bool   $canSubscribe Pode receber vídeo/áudio? (true = viewer)
 * @param int    $ttlSeconds   Validade do token em segundos (padrão: 6 horas)
 * @return string Token JWT assinado
 */
function livekit_generate_token(
    string $roomName,
    string $identity,
    string $displayName,
    bool   $canPublish   = false,
    bool   $canSubscribe = true,
    int    $ttlSeconds   = 21600
): string {
    $apiKey    = env('LIVEKIT_API_KEY', '');
    $apiSecret = env('LIVEKIT_API_SECRET', '');

    if (empty($apiKey) || empty($apiSecret)) {
        error_log('❌ livekit_jwt.php: LIVEKIT_API_KEY ou LIVEKIT_API_SECRET não definidos no .env');
        throw new RuntimeException('LiveKit não configurado no servidor.');
    }

    $now = time();

    $header = livekit_b64url(json_encode([
        'alg' => 'HS256',
        'typ' => 'JWT',
    ]));

    $payload = livekit_b64url(json_encode([
        'iss'  => $apiKey,
        'sub'  => $identity,
        'iat'  => $now,
        'exp'  => $now + $ttlSeconds,
        'nbf'  => $now,
        'name' => $displayName,
        'video' => [
            'room'         => $roomName,
            'roomJoin'     => true,
            'canPublish'   => $canPublish,
            'canSubscribe' => $canSubscribe,
            'canPublishData' => true, // Permite enviar dados (chat, etc.)
        ],
    ]));

    $signature = livekit_b64url(
        hash_hmac('sha256', "$header.$payload", $apiSecret, true)
    );

    return "$header.$payload.$signature";
}

/**
 * Codifica em Base64 URL-safe (sem padding)
 */
function livekit_b64url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
