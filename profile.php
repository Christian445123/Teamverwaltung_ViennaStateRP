<?php
require __DIR__ . '/bootstrap.php';
$user = Auth::requireLogin();
$db = DB::get();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $displayName = trim($_POST['display_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        if ($displayName !== '') {
            $stmt = $db->prepare("UPDATE users SET display_name = ?, email = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$displayName, $email ?: null, $user['id']]);
            flash('success', 'Profil aktualisiert.');
        }
    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        if ($user['password_hash'] && !password_verify($current, $user['password_hash'])) {
            flash('error', 'Aktuelles Passwort ist falsch.');
        } elseif (strlen($new) < 6) {
            flash('error', 'Neues Passwort muss mindestens 6 Zeichen haben.');
        } else {
            $stmt = $db->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            flash('success', 'Passwort geändert.');
        }
    } elseif ($action === 'unlink_discord') {
        $stmt = $db->prepare("UPDATE users SET discord_id = NULL, discord_username = NULL, discord_avatar = NULL WHERE id = ?");
        $stmt->execute([$user['id']]);
        flash('success', 'Discord-Verknüpfung entfernt.');
    }
    redirect(url('profile.php'));
}

$rank = Perm::rankOf($user);
$pageTitle = 'Profil';
$active = 'profile';
require __DIR__ . '/includes/header.php';
?>
<div class="page-header"><h1>Mein Profil</h1></div>

<div class="grid grid-2">
  <div class="card">
    <h2>Profildaten</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_profile">
      <div class="field">
        <label>Anzeigename</label>
        <input type="text" name="display_name" value="<?= e($user['display_name']) ?>" required>
      </div>
      <div class="field">
        <label>E-Mail</label>
        <input type="email" name="email" value="<?= e($user['email']) ?>">
      </div>
      <div class="field">
        <label>Rang</label>
        <div><span class="badge" style="background:<?= e($rank['color'] ?? '#5865F2') ?>"><?= e($rank['name'] ?? 'Kein Rang') ?></span></div>
      </div>
      <button class="btn" type="submit">Speichern</button>
    </form>
  </div>

  <div class="card">
    <h2>Discord-Verknüpfung</h2>
    <?php if ($user['discord_id']): ?>
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
        <img class="avatar" style="width:40px;height:40px;" src="<?= e(DiscordClient::avatarUrl($user['discord_id'], $user['discord_avatar'])) ?>" alt="">
        <div>
          <strong><?= e($user['discord_username']) ?></strong>
          <div class="card-sub">Verknüpft</div>
        </div>
      </div>
      <form method="post" data-confirm="Discord-Verknüpfung wirklich entfernen?">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="unlink_discord">
        <button class="btn secondary" type="submit">Verknüpfung entfernen</button>
      </form>
    <?php elseif (Settings::isDiscordConfigured()): ?>
      <p class="text-muted">Verknüpfe deinen Discord-Account für Login mit Discord und automatische Rollenvergabe.</p>
      <a href="<?= url('discord_login.php') ?>" class="btn discord">Mit Discord verknüpfen</a>
    <?php else: ?>
      <p class="text-muted">Discord ist noch nicht konfiguriert.</p>
    <?php endif; ?>
  </div>

  <?php if ($user['password_hash']): ?>
  <div class="card">
    <h2>Passwort ändern</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="change_password">
      <div class="field">
        <label>Aktuelles Passwort</label>
        <input type="password" name="current_password" required>
      </div>
      <div class="field">
        <label>Neues Passwort</label>
        <input type="password" name="new_password" required minlength="6">
      </div>
      <button class="btn" type="submit">Passwort ändern</button>
    </form>
  </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
