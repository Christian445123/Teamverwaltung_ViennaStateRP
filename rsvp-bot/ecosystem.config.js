'use strict';

module.exports = {
  apps: [
    {
      name: 'teamverwaltung-rsvp-bot',
      script: 'index.js',
      cwd: __dirname,
      instances: 1,
      exec_mode: 'fork',
      autorestart: true,
      // Wie beim Follower-Bot: großzügig hoch + exponentielles Backoff, damit ein kurzer
      // Crash-Loop (z. B. DB beim Start kurz nicht erreichbar) nicht sofort endgültig aufgibt.
      max_restarts: 1000,
      min_uptime: '30s',
      exp_backoff_restart_delay: 100,
      // Täglicher Neustart als zusätzliches Sicherheitsnetz gegen eine "eingeschlafene" Gateway-Session.
      cron_restart: '0 0 * * *',
      watch: false,
      env: {
        NODE_ENV: 'production',
      },
      out_file: './logs/out.log',
      error_file: './logs/error.log',
      merge_logs: true,
      time: true,
    },
  ],
};
