<?php
require __DIR__ . '/../bootstrap.php';
$user = Auth::requirePermission('discord.manage');
$db = DB::get();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_general') {
        Settings::setMany([
            'app_url' => trim($_POST['app_url'] ?? '') ?: null,
            'site_name' => trim($_POST['site_name'] ?? '') ?: null,
        ]);
        flash('success', 'Einstellungen gespeichert.');
    } elseif ($action === 'save_discord') {
        Settings::setMany([
            'discord_client_id' => trim($_POST['discord_client_id'] ?? '') ?: null,
            'discord_client_secret' => trim($_POST['discord_client_secret'] ?? '') ?: null,
            'discord_bot_token' => trim($_POST['discord_bot_token'] ?? '') ?: null,
            'discord_guild_id' => trim($_POST['discord_guild_id'] ?? '') ?: null,
            'discord_webhook_url' => trim($_POST['discord_webhook_url'] ?? '') ?: null,
            'discord_announce_channel_id' => trim($_POST['discord_announce_channel_id'] ?? '') ?: null,
        ]);
        flash('success', 'Discord-Einstellungen gespeichert.');
    } elseif ($action === 'sync_members') {
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
                $db->prepare("UPDATE users SET discord_username=?, discord_avatar=?, updated_at=datetime('now') WHERE id=?")
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
        }
        flash('success', "Discord-Rollen für {$count} Mitglieder synchronisiert.");
    }
    redirect(url('settings.php'));
}

$roles = Settings::isBotConfigured() ? DiscordClient::fetchGuildRoles() : [];
$channels = Settings::isBotConfigured() ? DiscordClient::fetchGuildChannels() : [];

$pageTitle = 'Einstellungen';
$active = 'settings';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header"><h1>Discord &amp; Einstellungen</h1></div>

<div class="card settings-section">
  <h2>Allgemein</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_general">
    <div class="field">
      <label>Seitenname</label>
      <input type="text" name="site_name" value="<?= e(Settings::get('site_name', 'Teamverwaltung')) ?>">
    </div>
    <div class="field">
      <label>App-URL (für Discord-OAuth-Redirect, ohne abschließenden Slash)</label>
      <input type="text" name="app_url" value="<?= e(Settings::get('app_url', '')) ?>" placeholder="<?= e(Settings::appUrl()) ?>">
      <div class="field-hint">Muss exakt der Redirect-URI im Discord Developer Portal entsprechen: <code><?= e(DiscordClient::redirectUri()) ?></code></div>
    </div>
    <button class="btn" type="submit">Speichern</button>
  </form>
</div>

<div class="card settings-section">
  <h2>Discord App (OAuth Login)</h2>
  <p class="text-muted">Erstelle eine Anwendung im <a href="https://discord.com/developers/applications" target="_blank" style="color:var(--accent);">Discord Developer Portal</a> und trage die Zugangsdaten hier ein. Als Redirect-URI dort <code><?= e(DiscordClient::redirectUri()) ?></code> eintragen.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_discord">
    <div class="form-row">
      <div class="field">
        <label>Client ID</label>
        <input type="text" name="discord_client_id" value="<?= e(Settings::get('discord_client_id', '')) ?>">
      </div>
      <div class="field">
        <label>Client Secret</label>
        <input type="text" name="discord_client_secret" value="<?= e(Settings::get('discord_client_secret', '')) ?>">
      </div>
    </div>

    <h3 style="margin-top:20px;">Bot (Rollen, Mitglieder-Sync, Events)</h3>
    <p class="text-muted" style="margin-top:-6px;">Erstelle im gleichen Portal einen Bot, lade ihn mit den Berechtigungen <em>Manage Roles</em>, <em>Manage Events</em> und <em>Send Messages</em> auf deinen Server ein (Rolle des Bots muss über den zu vergebenden Rollen stehen).</p>
    <div class="field">
      <label>Bot-Token</label>
      <input type="text" name="discord_bot_token" value="<?= e(Settings::get('discord_bot_token', '')) ?>">
    </div>
    <div class="field">
      <label>Server (Guild) ID</label>
      <input type="text" name="discord_guild_id" value="<?= e(Settings::get('discord_guild_id', '')) ?>">
    </div>
    <div class="form-row">
      <div class="field">
        <label>Ankündigungs-Channel</label>
        <?php if ($channels): ?>
        <select name="discord_announce_channel_id">
          <option value="">– keiner –</option>
          <?php foreach ($channels as $c): ?>
            <option value="<?= e($c['id']) ?>" <?= Settings::get('discord_announce_channel_id') === $c['id'] ? 'selected' : '' ?>>#<?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php else: ?>
        <input type="text" name="discord_announce_channel_id" value="<?= e(Settings::get('discord_announce_channel_id', '')) ?>" placeholder="Channel-ID (optional, falls kein Webhook)">
        <?php endif; ?>
      </div>
      <div class="field">
        <label>Webhook-URL (alternative Ankündigung, bevorzugt)</label>
        <input type="text" name="discord_webhook_url" value="<?= e(Settings::get('discord_webhook_url', '')) ?>" placeholder="https://discord.com/api/webhooks/...">
      </div>
    </div>
    <button class="btn" type="submit">Discord-Einstellungen speichern</button>
  </form>
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
      <button class="btn secondary" type="submit">Discord-Rollen für alle synchronisieren</button>
    </form>
  </div>
  <p class="field-hint" style="margin-top:10px;">Rollen-Zuordnungen werden pro Rang und Team unter <a href="<?= url('ranks.php') ?>" style="color:var(--accent);">Ränge</a> bzw. <a href="<?= url('teams.php') ?>" style="color:var(--accent);">Teams</a> festgelegt.</p>
  <?php if ($roles): ?>
    <h3 style="margin-top:18px;">Server-Rollen gefunden</h3>
    <div style="display:flex;flex-wrap:wrap;gap:6px;">
      <?php foreach ($roles as $r): if ($r['name'] === '@everyone') continue; ?>
        <span class="badge outline"><?= e($r['name']) ?></span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
