<?php
require __DIR__ . '/bootstrap.php';
$user = Auth::requirePermission('discord.manage');
$db = DB::get();

/**
 * Importiert/aktualisiert Discord-Server-Mitglieder als Teamverwaltung-Mitglieder.
 * Nur wer die "Team"- oder "High-Team"-Rolle hat, wird berücksichtigt (falls keine der
 * beiden Rollen zugeordnet ist: alle Server-Mitglieder, altes Verhalten als Fallback).
 * Gibt [created, matched, skipped, gated] zurück.
 */
function import_eligible_discord_members(PDO $db): array
{
    $members = DiscordClient::fetchGuildMembers();
    $teamRoleId = $db->query("SELECT discord_role_id FROM discord_extra_roles WHERE slug = 'team'")->fetchColumn();
    $highTeamRoleId = $db->query("SELECT discord_role_id FROM discord_extra_roles WHERE slug = 'high_team'")->fetchColumn();
    $gated = ($teamRoleId || $highTeamRoleId);

    $created = 0; $matched = 0; $skipped = 0;
    $lowestRank = $db->query("SELECT id FROM ranks ORDER BY level ASC LIMIT 1")->fetchColumn();

    foreach ($members as $m) {
        if (empty($m['user']) || !empty($m['user']['bot'])) continue;
        $roles = $m['roles'] ?? [];
        $eligible = !$gated
            || ($teamRoleId && in_array($teamRoleId, $roles, true))
            || ($highTeamRoleId && in_array($highTeamRoleId, $roles, true));
        if (!$eligible) { $skipped++; continue; }

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

    return [$created, $matched, $skipped, $gated];
}

/**
 * Führt einen Shell-Befehl im Projektverzeichnis aus (für den Git-Deploy unten). Nutzt
 * proc_open mit explizitem cwd statt "cd && …" per exec(), damit nicht vom aktuellen
 * Arbeitsverzeichnis des PHP-Prozesses abhängt. Alle Aufrufer übergeben ausschließlich
 * feste Befehle bzw. per escapeshellarg() escapete, aus Git selbst stammende Werte (nie
 * Benutzereingaben) — kein Injection-Risiko.
 */
function run_shell_command(string $cmd): array
{
    if (!function_exists('proc_open')) {
        return ['ok' => false, 'output' => 'proc_open() ist auf diesem Server deaktiviert (disable_functions).'];
    }
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = @proc_open($cmd, $descriptors, $pipes, __DIR__);
    if (!is_resource($process)) {
        return ['ok' => false, 'output' => 'Prozess konnte nicht gestartet werden.'];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    return ['ok' => $exitCode === 0, 'output' => trim($stdout . "\n" . $stderr)];
}

/**
 * Führt einen "pm2 ..."-Befehl aus, nachdem explizit alle gängigen Shell-Startdateien geladen
 * wurden. Weder reines HOME setzen noch "bash -lc" (Login-Shell) reichten: der PM2-Daemon, den
 * die SSH-Session kennt, wird offenbar über etwas gefunden, das nur eine bestimmte Startdatei
 * lädt (typischerweise nvm/PM2_HOME/PATH-Setup) — welche genau, ist je nach Server-Setup
 * unterschiedlich (mal .bashrc, mal .bash_profile/.profile). Statt uns auf Login-/Interactive-
 * Shell-Konventionen zu verlassen (die sich zwischen Distros unterscheiden), sourcen wir hier
 * einfach alle drei Kandidaten der Reihe nach, still und ohne Abbruch falls eine fehlt.
 */
function run_pm2_command(string $args): array
{
    $loadRcFiles = 'for f in ~/.bashrc ~/.bash_profile ~/.profile; do [ -f "$f" ] && . "$f" >/dev/null 2>&1; done; ';
    $result = run_shell_command('bash -c ' . escapeshellarg($loadRcFiles . 'pm2 ' . $args));
    if (!$result['ok']) {
        // Schlägt es trotzdem fehl, direkt die Diagnosedaten mitliefern, statt im Blindflug
        // weiter zu raten.
        $diag = trim(run_shell_command('bash -c ' . escapeshellarg($loadRcFiles . 'echo whoami=$(whoami) HOME=$HOME PM2_HOME=$PM2_HOME; which pm2; pm2 --version'))['output']);
        $pm2List = trim(run_shell_command('bash -c ' . escapeshellarg($loadRcFiles . 'pm2 jlist'))['output']);
        $result['output'] .= "\n\n[Diagnose] {$diag}\npm2 jlist: " . mb_substr($pm2List, 0, 800);
    }
    return $result;
}

/**
 * Git-Deploy nach dem Vorbild von Discordbot_Follower/deploy.sh bzw. dessen Webpanel-Button
 * "Deployen (git pull)": fast-forward-only, damit lokale Server-Änderungen nie stillschweigend
 * überschrieben werden — schlägt in dem Fall sauber fehl statt zu resetten. Ein Neustart eines
 * Prozesses (wie beim PM2-Bot) entfällt hier bewusst: PHP-Dateien werden pro Request neu
 * eingelesen, ein Deploy wirkt also sofort ohne Restart.
 */
function deploy_from_git(): array
{
    $branchResult = run_shell_command('git rev-parse --abbrev-ref HEAD');
    $branch = trim($branchResult['output']);
    if (!$branchResult['ok'] || $branch === '' || $branch === 'HEAD') {
        return ['ok' => false, 'message' => 'Konnte aktuellen Branch nicht ermitteln — ist das Projektverzeichnis ein Git-Repository?'];
    }

    $before = trim(run_shell_command('git rev-parse --short HEAD')['output']);

    $fetch = run_shell_command('git fetch --quiet origin ' . escapeshellarg($branch));
    if (!$fetch['ok']) {
        return ['ok' => false, 'message' => 'git fetch fehlgeschlagen: ' . $fetch['output']];
    }

    $merge = run_shell_command('git merge --ff-only --quiet ' . escapeshellarg('origin/' . $branch));
    if (!$merge['ok']) {
        return ['ok' => false, 'message' => 'Fast-Forward nicht möglich (lokale Änderungen auf dem Server?) — manueller Eingriff nötig. ' . $merge['output']];
    }

    $after = trim(run_shell_command('git rev-parse --short HEAD')['output']);
    if ($before === $after) {
        return ['ok' => true, 'message' => 'Bereits aktuell — keine neuen Commits.', 'changed' => false, 'diff' => ''];
    }
    $diffStat = trim(run_shell_command('git diff --stat ' . escapeshellarg($before) . ' ' . escapeshellarg($after))['output']);
    $message = "Deployment erfolgreich: {$before} → {$after}.";

    // Der RSVP-Gateway-Bot (rsvp-bot/) läuft dauerhaft per PM2 und merkt von neuen Dateien nichts
    // automatisch (anders als PHP, das pro Request neu eingelesen wird) — bei Änderungen dort
    // gleich mit neu starten. Best-effort: pm2 könnte fehlen/nicht eingerichtet sein, das darf den
    // eigentlichen Deploy-Erfolg nicht verfälschen.
    $changedFiles = run_shell_command('git diff --name-only ' . escapeshellarg($before) . ' ' . escapeshellarg($after))['output'];
    if (str_contains($changedFiles, 'rsvp-bot/')) {
        $restart = run_pm2_command('restart teamverwaltung-rsvp-bot');
        if ($restart['ok']) {
            $message .= ' RSVP-Bot wurde neu gestartet.';
            audit_log('settings.rsvp_bot_restart', 'RSVP-Bot nach Deploy erfolgreich neu gestartet (' . $before . ' → ' . $after . ').');
        } else {
            $message .= ' Hinweis: rsvp-bot/ hat sich geändert, „pm2 restart teamverwaltung-rsvp-bot” ist aber fehlgeschlagen — manuell neu starten. Fehler: ' . trim($restart['output']);
            audit_log('settings.rsvp_bot_restart_failed', 'RSVP-Bot-Neustart nach Deploy fehlgeschlagen: ' . trim($restart['output']));
        }
    }

    return ['ok' => true, 'message' => $message, 'changed' => true, 'diff' => $diffStat];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_webhooks') {
        $changed = [];
        foreach (Settings::DB_OVERRIDABLE as $key) {
            $newValue = trim($_POST[$key] ?? '');
            $before = Settings::get($key) ?? '';
            Settings::set($key, $newValue);
            if ($newValue !== $before) {
                $changed[] = $key;
            }
        }
        // Bewusst KEINE Klartext-Werte im Log — Webhook-URLs sind Zugangsdaten, die würden sonst
        // in den Aktivitäts-Log-Kanal selbst durchsickern. Nur welche Felder sich geändert haben.
        if ($changed) {
            audit_log('settings.save_webhooks', 'Geändert: ' . implode(', ', $changed));
        }
        flash('success', 'Webhooks & Kanäle gespeichert.');
        redirect(url('settings.php'));
    }

    if ($action === 'deploy') {
        $result = deploy_from_git();
        flash($result['ok'] ? 'success' : 'error', $result['message']);
        audit_log('settings.deploy', $result['message']);
        DiscordClient::postDeployLog($result['ok'], $result['message'], $result['diff'] ?? '', $user['display_name']);
        redirect(url('settings.php'));
    }

    if ($action === 'restart_bot') {
        $restart = run_pm2_command('restart teamverwaltung-rsvp-bot');
        if ($restart['ok']) {
            flash('success', 'RSVP-Bot wurde neu gestartet.');
            audit_log('settings.rsvp_bot_restart', 'RSVP-Bot manuell neu gestartet (Button).');
        } else {
            flash('error', '„pm2 restart teamverwaltung-rsvp-bot" ist fehlgeschlagen: ' . trim($restart['output']));
            audit_log('settings.rsvp_bot_restart_failed', 'Manueller RSVP-Bot-Neustart fehlgeschlagen: ' . trim($restart['output']));
        }
        redirect(url('settings.php'));
    }

    if ($action === 'sync_members') {
        [$created, $matched, $skipped, $gated] = import_eligible_discord_members($db);
        $msg = "Sync abgeschlossen: {$created} neue, {$matched} aktualisierte Mitglieder.";
        $msg .= $gated
            ? " {$skipped} ohne \"Team\"-/\"High-Team\"-Rolle übersprungen."
            : ' Hinweis: weder „Team"- noch „High-Team"-Rolle ist zugeordnet, es wurden alle Server-Mitglieder importiert.';
        audit_log('settings.sync_members', $msg);
        flash('success', $msg);
    } elseif ($action === 'reset_and_resync_members') {
        // Löscht alle Mitglieder außer dem eigenen (aktuell eingeloggten) Konto — verhindert,
        // dass man sich selbst aus der Teamverwaltung aussperrt — und importiert danach neu,
        // ausschließlich Mitglieder mit "Team"- oder "High-Team"-Rolle.
        $deleted = $db->prepare("DELETE FROM users WHERE id != ?");
        $deleted->execute([$user['id']]);
        $deletedCount = $deleted->rowCount();

        [$created, $matched, $skipped, $gated] = import_eligible_discord_members($db);
        $msg = "{$deletedCount} Mitglied(er) entfernt. Neu importiert: {$created}, aktualisiert: {$matched}.";
        $msg .= $gated
            ? " {$skipped} ohne \"Team\"-/\"High-Team\"-Rolle übersprungen."
            : ' Hinweis: weder „Team"- noch „High-Team"-Rolle ist zugeordnet, es wurden alle Server-Mitglieder importiert.';
        audit_log('settings.reset_and_resync_members', $msg);
        flash('success', $msg);
    } elseif ($action === 'sync_all_roles') {
        $activeUsers = $db->query("SELECT * FROM users WHERE status='active' AND discord_id IS NOT NULL")->fetchAll();
        $count = 0;
        foreach ($activeUsers as $u) {
            $result = DiscordClient::syncRolesForUser($u);
            if ($result['ok']) $count++;
            usleep(300000);
        }
        audit_log('settings.sync_all_roles', "Discord-Rollen für {$count} Mitglieder synchronisiert.");
        flash('success', "Discord-Rollen für {$count} Mitglieder synchronisiert.");
    } elseif ($action === 'sync_ranks_from_discord') {
        $activeUsers = $db->query("SELECT * FROM users WHERE status='active' AND discord_id IS NOT NULL")->fetchAll();
        $checked = 0;
        $upgraded = [];
        $flagChanges = 0;
        $permTagChanges = 0;
        foreach ($activeUsers as $u) {
            $checked++;
            $result = DiscordClient::pullFromDiscord($u);
            if (!empty($result['rank']['changed'])) {
                $upgraded[] = $u['display_name'] . ' → ' . $result['rank']['rank']['name'];
            }
            if (!empty($result['highTeam']['changed']) || !empty($result['team']['changed'])) {
                $flagChanges++;
            }
            if (!empty($result['permTags']['changed'])) {
                $permTagChanges++;
            }
            usleep(300000);
        }
        $msg = $upgraded
            ? "Geprüft: {$checked}. Hochgestuft: " . implode(', ', $upgraded)
            : "Geprüft: {$checked}. Keine Rang-Hochstufungen nötig.";
        if ($flagChanges > 0) {
            $msg .= " Team-/High-Team-Status bei {$flagChanges} Mitglied(ern) aktualisiert.";
        }
        if ($permTagChanges > 0) {
            $msg .= " Zusatzrollen bei {$permTagChanges} Mitglied(ern) aktualisiert.";
        }
        audit_log('settings.sync_ranks_from_discord', $msg);
        flash('success', $msg);
    } elseif ($action === 'save_extra_roles') {
        $stmt = $db->prepare("UPDATE discord_extra_roles SET discord_role_id = ? WHERE slug = ?");
        foreach (['team', 'high_team'] as $slug) {
            $roleId = trim($_POST['extra_role_' . $slug] ?? '');
            $stmt->execute([$roleId ?: null, $slug]);
        }
        audit_log('settings.save_extra_roles', 'Team/High-Team-Rollen-Zuordnung geändert');
        flash('success', 'Zusatzrollen gespeichert.');
    } elseif ($action === 'save_perm_tags') {
        $stmt = $db->prepare("UPDATE discord_perm_tags SET discord_role_id = ? WHERE slug = ?");
        foreach (DiscordClient::permTags() as $tag) {
            $roleId = trim($_POST['perm_tag_' . $tag['slug']] ?? '');
            $stmt->execute([$roleId ?: null, $tag['slug']]);
        }
        audit_log('settings.save_perm_tags', 'Zusatzrollen-Zuordnung geändert');
        flash('success', 'Zusatzrollen (Berechtigungs-Kennzeichnungen) gespeichert.');
    }
    redirect(url('settings.php'));
}

$currentCommit = trim(run_shell_command('git log -1 --format=' . escapeshellarg('%h %s (%cr)'))['output']);

$roles = Settings::isBotConfigured() ? DiscordClient::fetchGuildRoles() : [];
$extraRoles = Settings::isBotConfigured() ? DiscordClient::extraRoles() : [];
$extraRolesBySlug = [];
foreach ($extraRoles as $er) { $extraRolesBySlug[$er['slug']] = $er; }
$permTags = Settings::isBotConfigured() ? DiscordClient::permTags() : [];

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
  <h2>Deployment</h2>
  <p class="text-muted" style="margin-top:-8px;">Wie beim Discordbot_Follower-Webpanel: holt per <code>git fetch</code> + Fast-Forward-Merge den neuesten Stand vom Remote-Branch. Rein additiv — sind auf dem Server lokale Änderungen vorhanden, bricht der Vorgang sauber ab, statt sie zu überschreiben. Ein Neustart ist danach nicht nötig, PHP-Dateien werden pro Aufruf neu eingelesen.</p>
  <?php if ($currentCommit): ?>
    <p style="font-size:13px;">Aktuell live: <code><?= e($currentCommit) ?></code></p>
  <?php else: ?>
    <p class="text-muted" style="font-size:13px;">Konnte den aktuellen Git-Stand nicht ermitteln — ist dieses Verzeichnis ein Git-Checkout mit konfiguriertem Remote?</p>
  <?php endif; ?>
  <div class="btn-row">
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="deploy">
      <button class="btn" type="submit">🚀 Jetzt deployen (git pull)</button>
    </form>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="restart_bot">
      <button class="btn secondary" type="submit">🔁 RSVP-Bot neustarten</button>
    </form>
    <button class="btn secondary" type="button" onclick="location.reload()">🔄 Seite neu laden</button>
  </div>
  <p class="field-hint" style="margin-top:10px;">„RSVP-Bot neustarten" führt <code>pm2 restart teamverwaltung-rsvp-bot</code> aus — nützlich, falls der automatische Neustart nach einem Deploy fehlschlägt (siehe <code>rsvp-bot/README.md</code>).</p>
</div>

<div class="card settings-section">
  <h2>Konfigurationsstatus</h2>
  <p class="text-muted" style="margin-top:-8px;">Kern-Zugangsdaten (Datenbank, Client-Secret, Bot-Token, Public Key) werden ausschließlich in der Datei <code>.env</code> im Projektstammverzeichnis gepflegt — nicht über diese Oberfläche. Vorlage: <code>.env.example</code>. Webhooks &amp; die Ankündigungs-Channel-ID lassen sich dagegen unten direkt hier pflegen.</p>

  <?php status_row('App-URL', (bool) Settings::get('app_url'), 'APP_URL'); ?>
  <?php status_row('Discord Client ID', (bool) Settings::get('discord_client_id'), 'DISCORD_CLIENT_ID'); ?>
  <?php status_row('Discord Client Secret', (bool) Settings::get('discord_client_secret'), 'DISCORD_CLIENT_SECRET'); ?>
  <?php status_row('Discord Public Key (Buttons ohne Login)', (bool) Settings::get('discord_public_key'), 'DISCORD_PUBLIC_KEY'); ?>
  <?php status_row('Discord Bot-Token', (bool) Settings::get('discord_bot_token'), 'DISCORD_BOT_TOKEN'); ?>
  <?php status_row('Discord Server (Guild) ID', (bool) Settings::get('discord_guild_id'), 'DISCORD_GUILD_ID'); ?>
  <?php status_row('Ankündigungs-Webhook', (bool) Settings::get('discord_webhook_url'), 'DISCORD_WEBHOOK_URL'); ?>
  <?php status_row('Ankündigungs-Channel-ID', (bool) Settings::get('discord_announce_channel_id'), 'DISCORD_ANNOUNCE_CHANNEL_ID'); ?>
  <?php status_row('Aktivitäts-Log-Webhook (z. B. teambot-log)', (bool) Settings::get('discord_log_webhook_url'), 'DISCORD_LOG_WEBHOOK_URL'); ?>
  <?php status_row('Bewerbungs-Benachrichtigungs-Webhook', (bool) Settings::get('discord_applications_webhook_url'), 'DISCORD_APPLICATIONS_WEBHOOK_URL'); ?>
  <?php status_row('Development-/Fehler-Log-Webhook', (bool) Settings::get('discord_dev_log_webhook_url'), 'DISCORD_DEV_LOG_WEBHOOK_URL'); ?>

  <p class="field-hint" style="margin-top:14px;">Redirect-URI für das Discord Developer Portal: <code><?= e(DiscordClient::redirectUri()) ?></code><br>Interactions Endpoint URL (für Zu-/Absage-Buttons ohne Login): <code><?= e(Settings::appUrl() . '/discord_interactions.php') ?></code><br>Wichtig: Für funktionierende Zu-/Absage-Buttons müssen <strong>Bot-Token + Ankündigungs-Channel-ID</strong> gesetzt sein — ein reiner Webhook kann Button-Klicks nicht zuverlässig an <code>DISCORD_PUBLIC_KEY</code> ausliefern (ist zusätzlich ein Webhook konfiguriert, hat der Bot bei Buttons trotzdem Vorrang; ohne Bot-Kanal zeigt die Webhook-Nachricht nur den immer funktionierenden „Zum Meeting"-Link).</p>
</div>

<div class="card settings-section">
  <h2>Webhooks &amp; Kanäle</h2>
  <p class="text-muted" style="margin-top:-8px;">Direkt hier pflegbar, ohne die <code>.env</code> auf dem Server anzufassen. Ein gesetzter Wert überschreibt die <code>.env</code>; leer lassen und speichern setzt den Override zurück (dann gilt wieder der <code>.env</code>-Wert, falls vorhanden). Werte werden — genau wie in der <code>.env</code> — verschlüsselt gespeichert.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_webhooks">
    <div class="field">
      <label>Besprechungs-Ankündigungs-Webhook <?= Settings::hasDbOverride('discord_webhook_url') ? '<span class="badge outline" style="font-size:10px;">überschreibt .env</span>' : '' ?></label>
      <input type="text" name="discord_webhook_url" value="<?= e(Settings::get('discord_webhook_url') ?? '') ?>" placeholder="https://discord.com/api/webhooks/…">
    </div>
    <div class="field">
      <label>Besprechungs-Ankündigungs-Channel-ID <?= Settings::hasDbOverride('discord_announce_channel_id') ? '<span class="badge outline" style="font-size:10px;">überschreibt .env</span>' : '' ?></label>
      <input type="text" name="discord_announce_channel_id" value="<?= e(Settings::get('discord_announce_channel_id') ?? '') ?>" placeholder="z. B. 1524686181994856488">
    </div>
    <div class="field">
      <label>Aktivitäts-Log-Webhook (Teamlogs) <?= Settings::hasDbOverride('discord_log_webhook_url') ? '<span class="badge outline" style="font-size:10px;">überschreibt .env</span>' : '' ?></label>
      <input type="text" name="discord_log_webhook_url" value="<?= e(Settings::get('discord_log_webhook_url') ?? '') ?>" placeholder="https://discord.com/api/webhooks/…">
    </div>
    <div class="field">
      <label>Bewerbungs-Benachrichtigungs-Webhook <?= Settings::hasDbOverride('discord_applications_webhook_url') ? '<span class="badge outline" style="font-size:10px;">überschreibt .env</span>' : '' ?></label>
      <input type="text" name="discord_applications_webhook_url" value="<?= e(Settings::get('discord_applications_webhook_url') ?? '') ?>" placeholder="https://discord.com/api/webhooks/…">
    </div>
    <div class="field">
      <label>Development-/Fehler-Log-Webhook <?= Settings::hasDbOverride('discord_dev_log_webhook_url') ? '<span class="badge outline" style="font-size:10px;">überschreibt .env</span>' : '' ?></label>
      <input type="text" name="discord_dev_log_webhook_url" value="<?= e(Settings::get('discord_dev_log_webhook_url') ?? '') ?>" placeholder="https://discord.com/api/webhooks/…">
    </div>
    <button class="btn" type="submit">Speichern</button>
  </form>
</div>

<div class="card settings-section">
  <h2>Einrichtung</h2>
  <ol style="padding-left:20px;line-height:1.9;">
    <li>Im <a href="https://discord.com/developers/applications" target="_blank" style="color:var(--accent);">Discord Developer Portal</a> eine Anwendung erstellen, <em>Client ID</em> &amp; <em>Client Secret</em> in die <code>.env</code> eintragen.</li>
    <li>Dort unter <em>OAuth2 → Redirects</em> genau <code><?= e(DiscordClient::redirectUri()) ?></code> hinterlegen.</li>
    <li>Unter <em>General Information</em> den <em>Public Key</em> kopieren und als <code>DISCORD_PUBLIC_KEY</code> eintragen; dort auch die <em>Interactions Endpoint URL</em> auf <code><?= e(Settings::appUrl() . '/discord_interactions.php') ?></code> setzen (Discord prüft die URL beim Speichern sofort per Ping — <code>DISCORD_PUBLIC_KEY</code> muss also vorher gesetzt sein). Aktiviert die Zu-/Absage-Buttons direkt unter Besprechungs-Ankündigungen, ganz ohne Portal-Login.</li>
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
    <form method="post" data-confirm="Mitglieder mit der &quot;Team&quot;- oder &quot;High-Team&quot;-Rolle aus Discord importieren/abgleichen?">
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
  <p class="field-hint" style="margin-top:10px;">Rollen-Zuordnungen werden pro Rang und Team unter <a href="<?= url('ranks.php') ?>" style="color:var(--accent);">Ränge</a> bzw. <a href="<?= url('teams.php') ?>" style="color:var(--accent);">Teams</a> festgelegt. Die unten konfigurierte „Team"-Rolle ist dabei Voraussetzung: nur Mitglieder, die diese Rolle bereits auf Discord haben, werden überhaupt synchronisiert — in beide Richtungen.
  <strong>Mitglieder synchronisieren</strong> importiert nur Server-Mitglieder mit der „Team"- oder „High-Team"-Rolle (ist keine der beiden zugeordnet: alle Mitglieder).
  <strong>Rang/Team → Discord-Rollen</strong> überträgt den in der Teamverwaltung gesetzten Rang/Team als Discord-Rolle — aber nur an Mitglieder, die die „Team"-Rolle bereits haben; die „Team"-Rolle selbst vergibt die Teamverwaltung nie, die bleibt reine Discord-Pflege.
  <strong>Discord-Rollen → Rang</strong> macht es umgekehrt: anhand der aktuellen Discord-Rollen eines Mitglieds mit „Team"-Rolle wird der Rang in der Teamverwaltung ggf. hochgestuft (nie automatisch heruntergestuft) — das passiert außerdem automatisch bei jeder Discord-Anmeldung.</p>
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
        <label>„Team“ – Voraussetzung für Sync &amp; Rollenzuweisung; wird nie von der Teamverwaltung vergeben/entfernt</label>
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

<div class="card settings-section">
  <h2>Zusatzrollen (Discord-Zusatzrechte)</h2>
  <p class="text-muted" style="margin-top:-8px;">Reine Kennzeichnungen für Discord-seitige Zusatzrechte — schalten <strong>nichts</strong> in der Teamverwaltung frei, werden nie von der Teamverwaltung vergeben oder entfernt, sondern nur gelesen und als Badge angezeigt. Ein Mitglied kann mehrere gleichzeitig haben.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_perm_tags">
    <div class="grid grid-2">
      <?php foreach ($permTags as $tag): ?>
      <div class="field">
        <label>
          „<?= e($tag['label']) ?>“
          <?php if ($tag['slug'] === 'administrator'): ?>
            <span class="text-muted" style="font-weight:400;">— vorsichtig zuordnen, sensible Discord-Rolle</span>
          <?php endif; ?>
        </label>
        <?php if ($roles): ?>
        <select name="perm_tag_<?= e($tag['slug']) ?>">
          <option value="">– keine –</option>
          <?php foreach ($roles as $r): if ($r['name'] === '@everyone') continue; ?>
            <option value="<?= e($r['id']) ?>" <?= ($tag['discord_role_id'] ?? null) === $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php else: ?>
        <input type="text" name="perm_tag_<?= e($tag['slug']) ?>" value="<?= e($tag['discord_role_id'] ?? '') ?>" placeholder="Discord Rollen-ID">
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <button class="btn" type="submit" style="margin-top:8px;">Speichern</button>
  </form>
</div>

<div class="card settings-section" style="border-color:var(--danger);">
  <h2>Gefahrenzone: Mitgliederliste zurücksetzen</h2>
  <p class="text-muted" style="margin-top:-8px;">Entfernt <strong>alle</strong> Mitglieder aus der Teamverwaltung (dein eigenes, gerade eingeloggtes Konto bleibt erhalten) und importiert danach neu — ausschließlich Mitglieder mit „Team"- oder „High-Team"-Rolle. Löscht dabei auch alle Zu-/Absagen zu Besprechungen der entfernten Mitglieder; Besprechungen selbst bleiben erhalten. Nicht rückgängig zu machen.</p>
  <form method="post" data-confirm="Wirklich ALLE Mitglieder entfernen (außer dein eigenes Konto) und danach nur Mitglieder mit Team-/High-Team-Rolle neu importieren? Das kann nicht rückgängig gemacht werden.">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="reset_and_resync_members">
    <button class="btn danger" type="submit">Mitglieder entfernen &amp; neu synchronisieren</button>
  </form>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
