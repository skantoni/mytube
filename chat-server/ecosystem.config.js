// ============================================================
// PM2 Ecosystem Config — MyTube Chat Server
// Documentação: https://pm2.keymetrics.io/docs/usage/application-declaration/
// ============================================================
//
// Como usar:
//   Produção:     pm2 start ecosystem.config.js --env production
//   Desenvolvimento: pm2 start ecosystem.config.js --env development
//   Listar processos: pm2 list
//   Ver logs:     pm2 logs mytube-chat
//   Reiniciar:    pm2 restart mytube-chat
//   Parar:        pm2 stop mytube-chat
//   Auto-start no boot: pm2 startup && pm2 save
// ============================================================

module.exports = {
  apps: [
    {
      // ── Identidade ──────────────────────────────────────────
      name: 'mytube-chat',
      script: 'server.js',
      cwd: __dirname,

      // ── Modo de execução ────────────────────────────────────
      // 'fork'   = 1 processo (chat usa Socket.IO que tem estado)
      // 'cluster' = NÃO usar com Socket.IO sem Redis Adapter
      exec_mode: 'fork',
      instances: 1,

      // ── Gestão de memória e reinício ────────────────────────
      // Reinicia automaticamente se o processo crashar
      autorestart: true,
      // Reinicia se a memória ultrapassar 300MB
      max_memory_restart: '300M',
      // Espera 3s antes de marcar como estável (evita restart loop)
      min_uptime: '3s',
      // Máximo de tentativas de reinício antes de desistir
      max_restarts: 10,

      // ── Logs ────────────────────────────────────────────────
      // Logs separados por tipo
      output: './logs/chat-out.log',
      error: './logs/chat-error.log',
      // Formato de timestamp nos logs
      log_date_format: 'YYYY-MM-DD HH:mm:ss',
      // Combinar stdout+stderr num ficheiro único adicional
      merge_logs: false,

      // ── Watch (APENAS para dev, desactivado em prod) ─────────
      watch: false,
      ignore_watch: ['node_modules', 'logs'],

      // ── Variáveis de ambiente ────────────────────────────────
      // Estas são as variáveis BASE (sobrescritas pelas env abaixo)
      env: {
        NODE_ENV: 'development',
        PORT: 3001,
      },

      // Activadas com: pm2 start ecosystem.config.js --env production
      env_production: {
        NODE_ENV: 'production',
        PORT: 3001,
        // As credenciais sensíveis devem vir do ficheiro .env
        // O dotenv é carregado no server.js — não as definir aqui
      },

      env_development: {
        NODE_ENV: 'development',
        PORT: 3001,
      },
    },
  ],
};
