<?php
require __DIR__ . '/bootstrap.php';
$user = Auth::requireLogin();
$db = DB::get();
$canManage = Perm::has($user, 'teams.manage');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $id = isset($_POST['id']) ? (int) $_POST['id'] : null;

    if ($action === 'save') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $discordRoleId = trim($_POST['discord_role_id'] ?? '');
        if ($name === '') {
            flash('error', 'Name ist erforderlich.');
        } elseif ($id) {
            $stmt = $db->prepare("UPDATE teams SET name=?, description=?, discord_role_id=? WHERE id=?");
            $stmt->execute([$name, $description ?: null, $discordRoleId ?: null, $id]);
            flash('success', 'Team aktualisiert.');
        } else {
            $stmt = $db->prepare("INSERT INTO teams (name, description, discord_role_id) VALUES (?, ?, ?)");
            $stmt->execute([$name, $description ?: null, $discordRoleId ?: null]);
            flash('success', 'Team erstellt.');
        }
    } elseif ($action === 'delete' && $id) {
        // user_teams-Zuordnungen werden per ON DELETE CASCADE automatisch mitentfernt.
        $db->prepare("DELETE FROM teams WHERE id = ?")->execute([$id]);
        flash('success', 'Team gelöscht.');
    }
    redirect(url('teams.php'));
}

$teams = $db->query("
  SELECT t.*, (
    SELECT COUNT(*) FROM user_teams ut JOIN users u ON u.id = ut.user_id
    WHERE ut.team_id = t.id AND u.status='active'
  ) AS member_count
  FROM teams t ORDER BY t.name ASC
")->fetchAll();

$discordRoles = Settings::isBotConfigured() ? DiscordClient::fetchGuildRoles() : [];

$pageTitle = 'Teams';
$active = 'teams';
require __DIR__ . '/includes/header.php';
?>
<div class="page-header"><h1>Teams</h1></div>

<div class="grid grid-2">
  <?php foreach ($teams as $t): ?>
  <div class="card">
    <div class="card-title"><?= e($t['name']) ?></div>
    <div class="card-sub"><?= $t['member_count'] ?> Mitglied(er)</div>
    <?php if ($t['description']): ?><p><?= e($t['description']) ?></p><?php endif; ?>
    <?php if ($t['discord_role_id']): ?><p class="text-muted" style="font-size:12px;">Discord-Rolle: <?= e($t['discord_role_id']) ?></p><?php endif; ?>
    <?php if ($canManage): ?>
    <details style="margin-top:12px;">
      <summary style="cursor:pointer;color:var(--text-muted);font-size:13px;">Bearbeiten</summary>
      <form method="post" style="margin-top:12px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $t['id'] ?>">
        <div class="field"><label>Name</label><input type="text" name="name" value="<?= e($t['name']) ?>" required></div>
        <div class="field"><label>Beschreibung</label><textarea name="description"><?= e($t['description']) ?></textarea></div>
        <div class="field">
          <label>Discord-Rolle</label>
          <?php if ($discordRoles): ?>
          <select name="discord_role_id">
            <option value="">– keine –</option>
            <?php foreach ($discordRoles as $r): ?>
              <option value="<?= e($r['id']) ?>" <?= $t['discord_role_id'] === $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php else: ?>
          <input type="text" name="discord_role_id" value="<?= e($t['discord_role_id']) ?>" placeholder="Discord Rollen-ID">
          <?php endif; ?>
        </div>
        <div class="btn-row">
          <button class="btn small" type="submit">Speichern</button>
          <button class="btn danger small" type="submit" name="action" value="delete" data-confirm="Team wirklich löschen?">Löschen</button>
        </div>
      </form>
    </details>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<?php if ($canManage): ?>
<div class="card" style="max-width:480px;">
  <h2>Neues Team</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <div class="field"><label>Name</label><input type="text" name="name" required></div>
    <div class="field"><label>Beschreibung</label><textarea name="description"></textarea></div>
    <div class="field">
      <label>Discord-Rolle</label>
      <?php if ($discordRoles): ?>
      <select name="discord_role_id">
        <option value="">– keine –</option>
        <?php foreach ($discordRoles as $r): ?><option value="<?= e($r['id']) ?>"><?= e($r['name']) ?></option><?php endforeach; ?>
      </select>
      <?php else: ?>
      <input type="text" name="discord_role_id" placeholder="Discord Rollen-ID (optional)">
      <?php endif; ?>
    </div>
    <button class="btn" type="submit">Team erstellen</button>
  </form>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
