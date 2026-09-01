<?php
require __DIR__ . '/bootstrap.php';
$user = Auth::requirePermission('discord.manage');
$db = DB::get();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'sync_members') {
        $members = DiscordClient::fetchGuildMembers();
        $created = 0; $matched = 0;
        $lowestRank = $db->query("SELECT id FROM ranks ORDER BY level ASC LIMIT 1")->fetchColumn();
        foreach ($members as $m) {
            if (empty($m['user']) || !empty($m['user']['bot'])) continue;
            $discordId = $m['user']['id'];
            $stmt = $db->prepare("SELECT id FROM users WHERE discord_id = ?");
            $stmt->execute([$discordId]);
            $existing = $stmt->fetch();
            $name = ($m['nick'] ?? null) ?: ($m['user']['global_name'] ?? $m['user']['username']);
            if ($existing) {
                $db->prepare("UPDATE users SET discord_username=?, discord_avatar=?, updated_at=NOW() WHERE id=?")
                   ->execute([$m['user']['username'], $m['user']['avatar'], $existing['id']]);
                $matched++;
            } else {
                $db->prepare("INSERT INTO users (discord_id, discord_username, discord_avatar, display_name, rank_id, status)
                    VALUES (?, ?, ?, ?, ?, 'active')")
                   ->execute([$discordId, $m['user']['username'], $m['user']['avatar'], $name, $lowestRank ?: null]);
                $created++;
            }
        }
        flash('success', "Sync abgeschlossen: {$created} neue, {$matched} aktualisierte Mitglieder.");
    } elseif ($action === 'sync_all_roles') {
        $activeUsers = $db->query("SELECT * FROM users WHERE status='active' AND discord_id IS NOT NULL")->fetchAll();
        $count = 0;
        foreach ($activeUsers as $u) {
            $result = DiscordClient::syncRolesForUser($u);
            if ($result['ok']) $count++;
            usleep(300000);
        }
        flash('success', "Discord-Rollen für {$count} Mitglieder synchronisiert.");
    } elseif ($action === 'sync_ranks_from_discord') {
        $activeUsers = $db->query("SELECT * FROM users WHERE status='active' AND discord_id IS NOT NULL")->fetchAll();
        $checked = 0;
        $upgraded = [];
        $highTeamChanges = 0;
        foreach ($activeUsers as $u) {
            $checked++;
            $result = DiscordClient::pullFromDiscord($u);
            if (!empty($result['rank']['changed'])) {
                $upgraded[] = $u['display_name'] . ' → ' . $result['rank']['rank']['name'];
            }
            if (!empty($result['highTeam']['changed'])) {
                $highTeamChanges++;
            }
            usleep(300000);
        }
        $msg = $upgraded
            ? "Geprüft: {$checked}. Hochgestuft: " . implode(', ', $upgraded)
            : "Geprüft: {$checked}. Keine Rang-Hochstufungen nötig.";
        if ($highTeamChanges > 0) {
            $msg .= " High-Team-Status bei {$highTeamChanges} Mitglied(ern) aktualisiert.";
        }
        flash('success', $msg);
    } elseif ($action === 'save_extra_roles') {
        $stmt = $db->prepare("UPDATE discord_extra_roles SET discord_role_id = ? WHERE slug = ?");
        foreach (['team', 'high_team'] as $slug) {
            $roleId = trim($_POST['extra_role_' . $slug] ?? '');
            $stmt->execute([$roleId ?: null, $slug]);
        }
        flash('success', 'Zusatzrollen gespeichert.');
    }
    redirect(url('settings.php'));
}

$roles = Settings::isBotConfigured() ? DiscordClient::fetchGuildRoles() : [];
$extraRoles = Settings::isBotConfigured() ? DiscordClient::extraRoles() : [];
$extraRolesBySlug = [];
foreach ($extraRoles as $er) { $extraRolesBySlug[$er['slug']] = $er; }

function status_row(string $label, bool $ok, string $envVar): void {
    ?>
    <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);">
      <span><?= e($label) ?> <code style="color:var(--text-muted);font-size:12px;"><?= e($envVar) ?></code></span>
      <?php if ($ok): ?>
        <span class="badge" style="background:var(--success);">✓ gesetzt</span>
      <?php else: ?>
        <span class="badge outline">nicht gesetzt</span>
      <?php endif; ?>
    </div>
    <?php
}

$pageTitle = 'Einstellungen';
$active = 'settings';
require __DIR__ . '/includes/header.php';
?>
<div class="page-header"><h1>Discord &amp; Einstellungen</h1></div>

<div class="card settings-section">
  <h2>Konfigurationsstatus</h2>
  <p class="text-muted" style="margin-top:-8px;">Alle Zugangsdaten (Datenbank &amp; Discord) werden ausschließlich in der Datei <code>.env</code> im Projektstammverzeichnis gepflegt — nicht über diese Oberfläche. Das verhindert, dass Secrets in der Datenbank landen. Vorlage: <code>.env.example</code>.</p>

  <?php status_row('App-URL', (bool) Settings::get('app_url'), 'APP_URL'); ?>
  <?php status_row('Discord Client ID', (bool) Settings::get('discord_client_id'), 'DISCORD_CLIENT_ID'); ?>
  <?php status_row('Discord Client Secret', (bool) Settings::get('discord_client_secret'), 'DISCORD_CLIENT_SECRET'); ?>
  <?php status_row('Discord Bot-Token', (bool) Settings::get('discord_bot_token'), 'DISCORD_BOT_TOKEN'); ?>
  <?php status_row('Discord Server (Guild) ID', (bool) Settings::get('discord_guild_id'), 'DISCORD_GUILD_ID'); ?>
  <?php status_row('Ankündigungs-Webhook', (bool) Settings::get('discord_webhook_url'), 'DISCORD_WEBHOOK_URL'); ?>
  <?php status_row('Ankündigungs-Channel-ID', (bool) Settings::get('discord_announce_channel_id'), 'DISCORD_ANNOUNCE_CHANNEL_ID'); ?>

  <p class="field-hint" style="margin-top:14px;">Redirect-URI für das Discord Developer Portal: <code><?= e(DiscordClient::redirectUri()) ?></code></p>
</div>

<div class="card settings-section">
  <h2>Einrichtung</h2>
  <ol style="padding-left:20px;line-height:1.9;">
    <li>Im <a href="https://discord.com/developers/applications" target="_blank" style="color:var(--accent);">Discord Developer Portal</a> eine Anwendung erstellen, <em>Client ID</em> &amp; <em>Client Secret</em> in die <code>.env</code> eintragen.</li>
    <li>Dort unter <em>OAuth2 → Redirects</em> genau <code><?= e(DiscordClient::redirectUri()) ?></code> hinterlegen.</li>
    <li>Unter <em>Bot</em> einen Bot erstellen (Token in <code>DISCORD_BOT_TOKEN</code>), <em>Server Members Intent</em> aktivieren, und mit den Rechten <em>Manage Roles</em>, <em>Manage Events</em>, <em>Send Messages</em> auf den Server einladen (Bot-Rolle muss über den zu vergebenden Rollen stehen).</li>
    <li>Server-ID in <code>DISCORD_GUILD_ID</code> eintragen.</li>
    <li>Optional: <code>DISCORD_WEBHOOK_URL</code> oder <code>DISCORD_ANNOUNCE_CHANNEL_ID</code> für Besprechungs-Ankündigungen setzen.</li>
    <li>Webserver neu laden — Werte werden bei jedem Request aus der <code>.env</code> gelesen.</li>
  </ol>
</div>

<?php if (Settings::isBotConfigured()): ?>
<div class="card settings-section">
  <h2>Mitglieder &amp; Rollen</h2>
  <div class="btn-row">
    <form method="post" data-confirm="Mitglieder aus Discord importieren/abgleichen?">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="sync_members">
      <button class="btn secondary" type="submit">Mitglieder aus Discord synchronisieren</button>
    </form>
    <form method="post" data-confirm="Discord-Rollen für alle verknüpften Mitglieder anhand von Rang/Team setzen?">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="sync_all_roles">
      <button class="btn secondary" type="submit">Rang/Team → Discord-Rollen setzen</button>
    </form>
    <form method="post" data-confirm="Ränge aller verknüpften Mitglieder anhand ihrer aktuellen Discord-Rollen hochstufen?">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="sync_ranks_from_discord">
      <button class="btn secondary" type="submit">Discord-Rollen → Rang übernehmen</button>
    </form>
  </div>
  <p class="field-hint" style="margin-top:10px;">Rollen-Zuordnungen werden pro Rang und Team unter <a href="<?= url('ranks.php') ?>" style="color:var(--accent);">Ränge</a> bzw. <a href="<?= url('teams.php') ?>" style="color:var(--accent);">Teams</a> festgelegt.
  <strong>Rang/Team → Discord-Rollen</strong> überträgt den in der Teamverwaltung gesetzten Rang/Team als Discord-Rolle.
  <strong>Discord-Rollen → Rang</strong> macht es umgekehrt: anhand der aktuellen Discord-Rollen eines Mitglieds wird der Rang in der Teamverwaltung ggf. hochgestuft (nie automatisch heruntergestuft) — das passiert außerdem automatisch bei jeder Discord-Anmeldung.</p>
  <?php if ($roles): ?>
    <h3 style="margin-top:18px;">Server-Rollen gefunden</h3>
    <div style="display:flex;flex-wrap:wrap;gap:6px;">
      <?php foreach ($roles as $r): if ($r['name'] === '@everyone') continue; ?>
        <span class="badge outline"><?= e($r['name']) ?></span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card settings-section">
  <h2>Allgemeine Team-Rollen</h2>
  <p class="text-muted" style="margin-top:-8px;">Zwei Discord-Rollen, die sich nicht auf einen einzelnen Rang oder ein Team beschränken.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_extra_roles">
    <div class="form-row">
      <div class="field">
        <label>„Team“ – wird jedem aktiven, verknüpften Mitglied automatisch zusätzlich zu seinem Rang gesetzt</label>
        <?php if ($roles): ?>
        <select name="extra_role_team">
          <option value="">– keine –</option>
          <?php foreach ($roles as $r): if ($r['name'] === '@everyone') continue; ?>
            <option value="<?= e($r['id']) ?>" <?= ($extraRolesBySlug['team']['discord_role_id'] ?? null) === $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php else: ?>
        <input type="text" name="extra_role_team" value="<?= e($extraRolesBySlug['team']['discord_role_id'] ?? '') ?>" placeholder="Discord Rollen-ID">
        <?php endif; ?>
      </div>
      <div class="field">
        <label>„High-Team“ – wird nie automatisch vergeben/entfernt, nur der Status wird übernommen</label>
        <?php if ($roles): ?>
        <select name="extra_role_high_team">
          <option value="">– keine –</option>
          <?php foreach ($roles as $r): if ($r['name'] === '@everyone') continue; ?>
            <option value="<?= e($r['id']) ?>" <?= ($extraRolesBySlug['high_team']['discord_role_id'] ?? null) === $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php else: ?>
        <input type="text" name="extra_role_high_team" value="<?= e($extraRolesBySlug['high_team']['discord_role_id'] ?? '') ?>" placeholder="Discord Rollen-ID">
        <?php endif; ?>
      </div>
    </div>
    <button class="btn" type="submit">Speichern</button>
  </form>
  <p class="field-hint" style="margin-top:10px;">„High-Team“ wird bei jeder Discord-Anmeldung sowie über „Discord-Rollen → Rang übernehmen“ gelesen und als Badge bei den Mitgliedern angezeigt.</p>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
