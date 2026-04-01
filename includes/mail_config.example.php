<?php
// ============================================================
// Configuração de E-mail (SMTP)
// ============================================================
// 
// Copie este ficheiro para mail_config.php e preencha:
//   cp mail_config.example.php mail_config.php
//
// Para funcionar em hospedagem gratuita (InfinityFree, AeOFree, etc),
// é necessário usar SMTP externo (Gmail, Outlook, etc).
//
// COMO CONFIGURAR COM GMAIL:
// 1. Acesse: https://myaccount.google.com/security
// 2. Ative "Verificação em duas etapas"
// 3. Vá em "Senhas de app" (ou App Passwords)
// 4. Crie uma senha de app para "E-mail" > "Outro" (MyTube)
// 5. Copie a senha de 16 caracteres gerada
// 6. Cole abaixo em MAIL_PASSWORD
//
// ============================================================

// Ativar envio de email (true = SMTP, false = desativado)
define('MAIL_ENABLED', false);

// Configurações SMTP
define('MAIL_HOST', 'smtp.gmail.com');              // Servidor SMTP
define('MAIL_PORT', 587);                            // Porta (587 para TLS, 465 para SSL)
define('MAIL_ENCRYPTION', 'tls');                    // 'tls' ou 'ssl'
define('MAIL_USERNAME', 'seu-email@gmail.com');       // Seu email
define('MAIL_PASSWORD', 'xxxx xxxx xxxx xxxx');       // Senha de app (16 chars)

// Remetente
define('MAIL_FROM_ADDRESS', 'seu-email@gmail.com');   // Email do remetente
define('MAIL_FROM_NAME', 'MyTube');                   // Nome do remetente

// Debug SMTP (0 = desligado, 1 = erros, 2 = mensagens do servidor, 3 = tudo)
define('MAIL_DEBUG', 0);
