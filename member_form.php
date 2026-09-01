<?php
require __DIR__ . '/bootstrap.php';
$user = Auth::requirePermission('members.manage');
$db = DB::get();

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$member = null;
if ($id) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $member = $stmt->fetch();
    if (!$member) {
        flash('error', 'Mitglied nicht gefunden.');
        redirect(url('members.php'));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete' && $member) {
        if (!empty($member['is_superadmin'])) {
            flash('error', 'Der Administrator-Account kann nicht deaktiviert werden.');
            redirect(url('member_form.php?id=' . $member['id']));
        }
        $stmt = $db->prepare("UPDATE users SET status = 'inactive' WHERE id = ?");
        $stmt->execute([$member['id']]);
        audit_log('member.deactivate', $member['display_name']);
        flash('success', 'Mitglied wurde deaktiviert.');
        redirect(url('members.php'));
    }

    if ($action === 'sync_discord' && $member) {
        $result = DiscordClient::syncRolesForUser($member);
        if ($result['ok']) {
            flash('success', $result['changed'] ? 'Discord-Rollen aktualisiert.' : 'Discord-Rollen waren bereits aktuell.');
        } else {
            flash('error', 'Discord-Sync fehlgeschlagen: ' . ($result['error'] ?? 'Unbekannter Fehler'));
        }
        redirect(url('member_form.php?id=' . $member['id']));
    }

    $displayName = trim($_POST['display_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $rankId = $_POST['rank_id'] !== '' ? (int) $_POST['rank_id'] : null;
    $teamId = $_POST['team_id'] !== '' ? (int) $_POST['team_id'] : null;
    $notes = trim($_POST['notes'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $discordId = trim($_POST['discord_id'] ?? '');

    if ($displayName === '') {
        flash('error', 'Anzeigename ist erforderlich.');
        redirect(url('member_form.php') . ($id ? '?id=' . $id : ''));
    }

    if ($discordId !== '' && !preg_match('/^\d{15,25}$/', $discordId)) {
        flash('error', 'Discord-ID sieht ungültig aus (nur Ziffern, 15–25 Stellen).');
        redirect(url('member_form.php') . ($id ? '?id=' . $id : ''));
    }
    if ($discordId !== '') {
        $dupStmt = $db->prepare("SELECT id FROM users WHERE discord_id = ? AND id != ?");
        $dupStmt->execute([$discordId, $member['id'] ?? 0]);
        if ($dupStmt->fetch()) {
            flash('error', 'Diese Discord-ID ist bereits einem anderen Mitglied zugeordnet.');
            redirect(url('member_form.php') . ($id ? '?id=' . $id : ''));
        }
    }

    // Bei neu eingetragener/geänderter Discord-ID Benutzername & Avatar vom Server nachladen,
    // damit die Verknüpfung sofort vollständig aussieht (nicht nur die nackte ID).
    $discordUsername = $member['discord_username'] ?? null;
    $discordAvatar = $member['discord_avatar'] ?? null;
    if ($discordId !== '' && $discordId !== ($member['discord_id'] ?? null) && Settings::isBotConfigured()) {
        $discordMember = DiscordClient::getGuildMember($discordId);
        if ($discordMember && !empty($discordMember['user'])) {
            $discordUsername = $discordMember['user']['username'] ?? null;
            $discordAvatar = $discordMember['user']['avatar'] ?? null;
        }
    } elseif ($discordId === '') {
        $discordUsername = null;
        $discordAvatar = null;
    }

    if ($member) {
        $stmt = $db->prepare("UPDATE users SET display_name=?, email=?, rank_id=?, team_id=?, notes=?, username=?, discord_id=?, discord_username=?, discord_avatar=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([$displayName, $email ?: null, $rankId, $teamId, $notes ?: null, $username ?: null, $discordId ?: null, $discordUsername, $discordAvatar, $member['id']]);
        if ($password !== '') {
            $db->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($password, PASSWORD_DEFAULT), $member['id']]);
        }
        $memberId = $member['id'];
        audit_log('member.update', $displayName);
        flash('success', 'Mitglied aktualisiert.');
    } else {
        $stmt = $db->prepare("INSERT INTO users (display_name, email, rank_id, team_id, notes, username, password_hash, discord_id, discord_username, discord_avatar, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')");
        $stmt->execute([$displayName, $email ?: null, $rankId, $teamId, $notes ?: null, $username ?: null, $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null, $discordId ?: null, $discordUsername, $discordAvatar]);
        $memberId = $db->lastInsertId();
        audit_log('member.create', $displayName);
        flash('success', 'Mitglied hinzugefügt.');
    }

    if (Settings::isBotConfigured()) {
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$memberId]);
        $updated = $stmt->fetch();
        if ($updated['discord_id']) {
            DiscordClient::syncRolesForUser($updated);
        }
    }

    redirect(url('members.php'));
}

$ranks = $db->query("SELECT * FROM ranks ORDER BY level DESC")->fetchAll();
$teams = $db->query("SELECT * FROM teams ORDER BY name ASC")->fetchAll();

$pageTitle = $member ? 'Mitglied bearbeiten' : 'Mitglied hinzufügen';
$active = 'members';
require __DIR__ . '/includes/header.php';
?>
<div class="page-header"><h1><?= e($pageTitle) ?></h1></div>

<div class="card" style="max-width:640px;">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <div class="field">
      <label>Anzeigename *</label>
      <input type="text" name="display_name" value="<?= e($member['display_name'] ?? '') ?>" required>
    </div>
    <div class="form-row">
      <div class="field">
        <label>Rang</label>
        <select name="rank_id">
          <option value="">– keiner –</option>
          <?php foreach ($ranks as $r): ?>
            <option value="<?= $r['id'] ?>" <?= ($member['rank_id'] ?? null) == $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label>Team</label>
        <select name="team_id">
          <option value="">– keins –</option>
          <?php foreach ($teams as $t): ?>
            <option value="<?= $t['id'] ?>" <?= ($member['team_id'] ?? null) == $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="field">
        <label>E-Mail</label>
        <input type="email" name="email" value="<?= e($member['email'] ?? '') ?>">
      </div>
      <div class="field">
        <label>Benutzername (für Login ohne Discord)</label>
        <input type="text" name="username" value="<?= e($member['username'] ?? '') ?>">
      </div>
    </div>
    <div class="field">
      <label>Discord-ID</label>
      <input type="text" name="discord_id" value="<?= e($member['discord_id'] ?? '') ?>" pattern="\d{15,25}" placeholder="z. B. 123456789012345678">
      <div class="field-hint">Verknüpft das Mitglied direkt mit diesem Discord-Account (Rechtsklick auf den Nutzer in Discord → ID kopieren, Entwicklermodus muss aktiviert sein) — Benutzername &amp; Avatar werden automatisch nachgeladen, danach werden Rang/Team-Discord-Rollen synchronisiert. Leer lassen, um keine Verknüpfung zu setzen bzw. eine bestehende zu entfernen.</div>
    </div>
    <div class="field">
      <label>Passwort <?= $member ? '(leer lassen, um es nicht zu ändern)' : '' ?></label>
      <input type="password" name="password" minlength="6">
    </div>
    <div class="field">
      <label>Notizen</label>
      <textarea name="notes"><?= e($member['notes'] ?? '') ?></textarea>
    </div>
    <div class="btn-row">
      <button class="btn" type="submit">Speichern</button>
      <a href="<?= url('members.php') ?>" class="btn secondary">Abbrechen</a>
    </div>
  </form>
</div>

<?php if ($member): ?>
<div class="card" style="max-width:640px;">
  <h2>Discord</h2>
  <?php if ($member['discord_id']): ?>
    <p>Verknüpft mit <strong><?= e($member['discord_username']) ?></strong></p>
    <?php if (Settings::isBotConfigured()): ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="sync_discord">
        <button class="btn secondary" type="submit">Discord-Rollen jetzt synchronisieren</button>
      </form>
    <?php endif; ?>
  <?php else: ?>
    <p class="text-muted">Dieses Mitglied hat noch keinen Discord-Account verknüpft. Discord-ID oben eintragen, oder das Mitglied verknüpft sich selbst über "Mit Discord anmelden" bzw. im eigenen Profil.</p>
  <?php endif; ?>
</div>

<?php if (!$member['is_superadmin']): ?>
<div class="card" style="max-width:640px;border-color:var(--danger);">
  <h2>Gefahrenzone</h2>
  <form method="post" data-confirm="Mitglied wirklich deaktivieren?">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <button class="btn danger" type="submit">Mitglied deaktivieren</button>
  </form>
</div>
<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
