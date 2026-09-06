<?php
require __DIR__ . '/bootstrap.php';

if (current_user()) {
    redirect(url('index.php'));
}

$setupMode = !Auth::hasAnyUsers();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if ($setupMode) {
        $displayName = trim($_POST['display_name'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($displayName === '' || $username === '' || strlen($password) < 6) {
            $error = 'Bitte alle Felder ausfüllen. Das Passwort muss mindestens 6 Zeichen haben.';
        } else {
            $db = DB::get();
            $rankId = $db->query("SELECT id FROM ranks ORDER BY level DESC LIMIT 1")->fetchColumn();
            $stmt = $db->prepare("INSERT INTO users (username, password_hash, display_name, rank_id, is_superadmin, status)
                VALUES (?, ?, ?, ?, 1, 'active')");
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $displayName, $rankId ?: null]);
            $user = ['id' => $db->lastInsertId()];
            $fullUser = DB::get()->query("SELECT * FROM users WHERE id = " . (int) $user['id'])->fetch();
            Auth::login($fullUser);
            audit_log('auth.setup', "Erstes Administrator-Konto erstellt: {$displayName}");
            flash('success', 'Willkommen! Dein Administrator-Konto wurde erstellt.');
            redirect(url('settings.php'));
        }
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $user = Auth::attemptLogin($username, $password);
        if ($user) {
            Auth::login($user);
            audit_log('auth.login', 'Login per Benutzername/Passwort');
            redirect(url('index.php'));
        }
        $error = 'Benutzername oder Passwort ist falsch.';
    }
}

$discordAvailable = Settings::isDiscordConfigured();

$pageTitle = $setupMode ? 'Ersteinrichtung' : 'Anmelden';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-card">
  <h1><?= $setupMode ? 'Ersteinrichtung' : 'Anmelden' ?></h1>
  <?php if ($setupMode && $discordAvailable): ?>
    <p class="text-muted" style="text-align:center;margin-top:-10px;">Mit Discord anmelden, um automatisch das erste Administrator-Konto zu erstellen — oder unten manuell eines anlegen.</p>
  <?php elseif ($setupMode): ?>
    <p class="text-muted" style="text-align:center;margin-top:-10px;">Erstelle das erste Administrator-Konto für deine Teamverwaltung.</p>
  <?php endif; ?>

  <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>

  <?php if ($discordAvailable): ?>
  <a class="btn discord" style="width:100%;justify-content:center;" href="<?= url('discord_login.php') ?>">
    Mit Discord anmelden
  </a>
  <div class="divider"><?= $setupMode ? 'oder manuell einrichten' : 'oder' ?></div>
  <?php endif; ?>

  <form method="post">
    <?= csrf_field() ?>
    <?php if ($setupMode): ?>
    <div class="field">
      <label>Anzeigename</label>
      <input type="text" name="display_name" required>
    </div>
    <?php endif; ?>
    <div class="field">
      <label>Benutzername</label>
      <input type="text" name="username" required autofocus>
    </div>
    <div class="field">
      <label>Passwort</label>
      <input type="password" name="password" required minlength="6">
    </div>
    <button class="btn" type="submit" style="width:100%;justify-content:center;">
      <?= $setupMode ? 'Konto erstellen' : 'Anmelden' ?>
    </button>
  </form>
  <?php if (!$setupMode): ?>
  <div class="divider">oder</div>
  <a class="btn secondary" style="width:100%;justify-content:center;" href="<?= url('careers.php') ?>">
    📋 Offene Stellen ansehen
  </a>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
