'use strict';

/**
 * RSVP-Bot für die Teamverwaltung: nimmt Klicks auf die Teilnehmen/Vielleicht/Absagen-Buttons
 * unter Besprechungs-Ankündigungen entgegen — über Discords GATEWAY (Websocket), nicht über den
 * HTTP-Interactions-Endpoint (discord_interactions.php).
 *
 * Warum: Der HTTP-Interactions-Endpoint verlangt, dass Discord unseren Server innerhalb von 3
 * Sekunden per HTTPS erreicht (Cloudflare → nginx → PHP-FPM dazwischen — jede Station ist ein
 * potenzieller Verzögerungs-/Blockade-Punkt). Über die Gateway-Verbindung liefert Discord die
 * Interaktion direkt an diesen bereits laufenden, dauerhaft verbundenen Prozess — kein
 * HTTP-Request von Discord an unseren Server nötig, also auch keine der oben genannten
 * Verzögerungsquellen. Exakt der gleiche, bereits bewährte Mechanismus wie bei den anderen
 * ViennaStateRP-Bots (Discordbot_Follower, Discordbot_Ticket).
 *
 * WICHTIG: Damit Discord Interaktionen wirklich per Gateway statt per HTTP ausliefert, darf die
 * "Interactions Endpoint URL" im Discord Developer Portal (General Information) für DIESE
 * Anwendung nicht gesetzt sein — ist dort eine URL eingetragen, geht IMMER alles per HTTP dorthin,
 * unabhängig davon, ob zusätzlich eine Gateway-Verbindung besteht. Feld dort leeren/speichern.
 *
 * Schreibt direkt in dieselbe MySQL-Datenbank wie die Teamverwaltung (PHP) — dieselbe Logik wie
 * discord_interactions.php, nur eben über einen anderen Zustellweg.
 */

require('dotenv').config();
const { Client, GatewayIntentBits, Events } = require('discord.js');
const mysql = require('mysql2/promise');

const client = new Client({
  intents: [GatewayIntentBits.Guilds, GatewayIntentBits.GuildMembers],
});

let pool;
function db() {
  if (!pool) {
    pool = mysql.createPool({
      host: process.env.DB_HOST || '127.0.0.1',
      port: Number(process.env.DB_PORT || 3306),
      user: process.env.DB_USER,
      password: process.env.DB_PASS,
      database: process.env.DB_NAME || 'teamverwaltung',
      waitForConnections: true,
      connectionLimit: 5,
    });
  }
  return pool;
}

/** Ein Query mit genau einer erwarteten Ergebniszeile (oder null). */
async function queryOne(sql, params) {
  const [rows] = await db().query(sql, params);
  return rows[0] || null;
}

/**
 * Postet einen Aktivitäts-Log-Eintrag optional zusätzlich in Discord (Teamlogs) — Pendant zu
 * audit_log() in src/helpers.php (PHP), damit RSVP-per-Gateway genauso sichtbar bleibt wie
 * RSVP-per-Portal. Rein informativ, best-effort: ohne DISCORD_LOG_WEBHOOK_URL passiert nichts,
 * ein Fehler dabei bricht nie den eigentlichen RSVP-Vorgang ab. Anders als die PHP-Seite liegt
 * der Webhook hier im Klartext in der .env dieses Bots (kein gemeinsames Verschlüsselungs-Setup
 * mit der PHP-App nötig).
 */
async function postActivityLog(action, details, actorName) {
  const webhook = process.env.DISCORD_LOG_WEBHOOK_URL;
  if (!webhook) return;
  try {
    await fetch(webhook.replace(/\/$/, '') + '?wait=false', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        embeds: [{
          title: action,
          description: details,
          color: 0x5865f2,
          footer: { text: actorName ? `von ${actorName}` : 'System' },
          timestamp: new Date().toISOString(),
        }],
      }),
      signal: AbortSignal.timeout(4000),
    });
  } catch (err) {
    console.error('[postActivityLog] fehlgeschlagen:', err.message);
  }
}

client.once(Events.ClientReady, (c) => {
  console.log(`[rsvp-bot] bereit als ${c.user.tag}`);
});

client.on(Events.InteractionCreate, async (interaction) => {
  if (!interaction.isButton()) return;
  if (!interaction.customId.startsWith('rsvp:')) return;

  const parts = interaction.customId.split(':');
  if (parts.length !== 3) return;
  const meetingId = parseInt(parts[1], 10);
  const status = parts[2];
  if (!Number.isInteger(meetingId) || !['accepted', 'maybe', 'declined'].includes(status)) {
    return;
  }

  // Sofort quittieren ("Bot denkt nach …", ephemeral) — läuft direkt über die bestehende
  // Gateway-Verbindung, keine Netzwerkstrecke zu unserem eigenen Server nötig, daher praktisch
  // nie ein Timeout-Risiko wie beim HTTP-Endpoint.
  await interaction.deferReply({ ephemeral: true });

  try {
    const meeting = await queryOne('SELECT * FROM meetings WHERE id = ?', [meetingId]);
    if (!meeting) {
      await interaction.editReply('Diese Besprechung existiert nicht mehr.');
      return;
    }
    if (meeting.status === 'cancelled') {
      await interaction.editReply('Diese Besprechung wurde abgesagt.');
      return;
    }

    // Nur Mitglieder mit der "Team"-Rolle dürfen antworten (falls eine konfiguriert ist) —
    // gleiche Regel wie discord_interactions.php.
    const teamRoleRow = await queryOne("SELECT discord_role_id FROM discord_extra_roles WHERE slug = 'team'");
    const teamRoleId = teamRoleRow ? teamRoleRow.discord_role_id : null;
    const memberRoleIds = interaction.member.roles.cache.map((r) => r.id);
    if (teamRoleId && !memberRoleIds.includes(teamRoleId)) {
      await interaction.editReply('Du bist nicht berechtigt, auf Besprechungen zu antworten.');
      return;
    }

    // Nutzer anhand der Discord-ID finden oder (minimal) neu anlegen — RSVP funktioniert dadurch
    // auch für Mitglieder, die sich noch nie im Portal angemeldet haben.
    let user = await queryOne('SELECT * FROM users WHERE discord_id = ?', [interaction.user.id]);
    if (!user) {
      const lowestRank = await queryOne('SELECT id FROM ranks ORDER BY level ASC LIMIT 1');
      const displayName = interaction.member.nickname || interaction.user.globalName || interaction.user.username;
      await db().query(
        `INSERT INTO users (discord_id, discord_username, discord_avatar, display_name, rank_id, status)
         VALUES (?, ?, ?, ?, ?, 'active')`,
        [interaction.user.id, interaction.user.username, interaction.user.avatar, displayName, lowestRank ? lowestRank.id : null]
      );
      user = await queryOne('SELECT * FROM users WHERE discord_id = ?', [interaction.user.id]);
    }

    // Berechtigung "meetings.respond" prüfen — Superadmin hat immer alle Rechte (siehe Perm::has() in PHP).
    let hasPermission = !!user.is_superadmin;
    if (!hasPermission && user.rank_id) {
      const rank = await queryOne('SELECT permissions FROM ranks WHERE id = ?', [user.rank_id]);
      if (rank) {
        const perms = JSON.parse(rank.permissions || '[]');
        hasPermission = perms.includes('meetings.respond');
      }
    }
    if (!hasPermission) {
      await interaction.editReply('Dir fehlt die Berechtigung, auf Besprechungen zu antworten.');
      return;
    }

    await db().query(
      `INSERT INTO meeting_attendees (meeting_id, user_id, status, responded_at) VALUES (?, ?, ?, NOW())
       ON DUPLICATE KEY UPDATE status = VALUES(status), responded_at = VALUES(responded_at)`,
      [meetingId, user.id, status]
    );

    const actorName = user.display_name || interaction.user.username;
    await db().query(
      'INSERT INTO audit_log (user_id, action, details) VALUES (NULL, ?, ?)',
      ['meeting.rsvp_discord', `Besprechung #${meetingId} → ${status} (${actorName})`]
    );
    await postActivityLog('meeting.rsvp_discord', `Besprechung #${meetingId} → ${status}`, actorName);

    const labels = {
      accepted: '✅ Du hast zugesagt!',
      maybe: '❔ Als „vielleicht“ vermerkt.',
      declined: '❌ Du hast abgesagt.',
    };
    await interaction.editReply(labels[status]);
  } catch (err) {
    console.error('[rsvp-bot] Fehler:', err);
    try {
      await interaction.editReply('Es ist ein Fehler aufgetreten. Bitte später erneut versuchen oder im Dashboard antworten.');
    } catch {
      // Interaktion evtl. schon abgelaufen — nichts weiter zu tun.
    }
  }
});

client.login(process.env.DISCORD_BOT_TOKEN);
