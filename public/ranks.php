<?php
require __DIR__ . '/../bootstrap.php';
$user = Auth::requirePermission('ranks.manage');
$db = DB::get();
$allPerms = Perm::all();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $id = isset($_POST['id']) ? (int) $_POST['id'] : null;

    if ($action === 'save') {
        $name = trim($_POST['name'] ?? '');
        $level = (int) ($_POST['level'] ?? 0);
        $color = trim($_POST['color'] ?? '#5865F2');
        $discordRoleId = trim($_POST['discord_role_id'] ?? '');
        $perms = array_values(array_intersect($_POST['permissions'] ?? [], array_keys($allPerms)));

        if ($name === '') {
            flash('error', 'Name ist erforderlich.');
        } elseif ($id) {
            $stmt = $db->prepare("UPDATE ranks SET name=?, level=?, color=?, permissions=?, discord_role_id=? WHERE id=?");
            $stmt->execute([$name, $level, $color, json_encode($perms), $discordRoleId ?: null, $id]);
            flash('success', 'Rang aktualisiert.');
        } else {
            $stmt = $db->prepare("INSERT INTO ranks (name, level, color, permissions, discord_role_id) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$name, $level, $color, json_encode($perms), $discordRoleId ?: null]);
            flash('success', 'Rang erstellt.');
        }
    } elseif ($action === 'delete' && $id) {
        $db->prepare("UPDATE users SET rank_id = NULL WHERE rank_id = ?")->execute([$id]);
        $db->prepare("DELETE FROM ranks WHERE id = ?")->execute([$id]);
        flash('success', 'Rang gelöscht.');
    }
    redirect(url('ranks.php'));
}

$ranks = $db->query("
  SELECT r.*, (SELECT COUNT(*) FROM users u WHERE u.rank_id = r.id AND u.status='active') AS member_count
  FROM ranks r ORDER BY r.level DESC
")->fetchAll();

$discordRoles = Settings::isBotConfigured() ? DiscordClient::fetchGuildRoles() : [];

$pageTitle = 'Ränge';
$active = 'ranks';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header"><h1>Ränge &amp; Berechtigungen</h1></div>
<p class="text-muted" style="margin-top:-14px;">Ränge bestimmen Berechtigungen in der Teamverwaltung und können optional automatisch mit einer Discord-Rolle synchronisiert werden.</p>

<div class="grid grid-2">
  <?php foreach ($ranks as $r): $perms = json_decode($r['permissions'], true) ?: []; ?>
  <div class="card">
    <div style="display:flex;align-items:center;gap:8px;">
      <span class="color-dot" style="background:<?= e($r['color']) ?>"></span>
      <span class="card-title"><?= e($r['name']) ?></span>
    </div>
    <div class="card-sub">Stufe <?= $r['level'] ?> · <?= $r['member_count'] ?> Mitglied(er)</div>
    <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:6px;">
      <?php foreach ($perms as $p): ?><span class="badge outline"><?= e($allPerms[$p] ?? $p) ?></span><?php endforeach; ?>
      <?php if (!$perms): ?><span class="text-muted" style="font-size:13px;">Keine besonderen Berechtigungen</span><?php endif; ?>
    </div>
    <?php if ($r['discord_role_id']): ?><p class="text-muted" style="font-size:12px;margin-top:8px;">Discord-Rolle: <?= e($r['discord_role_id']) ?></p><?php endif; ?>

    <details style="margin-top:12px;">
      <summary style="cursor:pointer;color:var(--text-muted);font-size:13px;">Bearbeiten</summary>
      <form method="post" style="margin-top:12px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $r['id'] ?>">
        <div class="form-row">
          <div class="field"><label>Name</label><input type="text" name="name" value="<?= e($r['name']) ?>" required></div>
          <div class="field"><label>Stufe (höher = mehr Gewicht)</label><input type="text" name="level" value="<?= $r['level'] ?>" required></div>
        </div>
        <div class="field"><label>Farbe</label><input type="text" name="color" value="<?= e($r['color']) ?>"></div>
        <div class="field">
          <label>Berechtigungen</label>
          <?php foreach ($allPerms as $key => $label): ?>
            <label style="font-weight:400;display:flex;align-items:center;gap:8px;margin-bottom:4px;">
              <input type="checkbox" name="permissions[]" value="<?= e($key) ?>" style="width:auto;" <?= in_array($key, $perms, true) ? 'checked' : '' ?>>
              <?= e($label) ?>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="field">
          <label>Discord-Rolle (automatische Vergabe)</label>
          <?php if ($discordRoles): ?>
          <select name="discord_role_id">
            <option value="">– keine –</option>
            <?php foreach ($discordRoles as $dr): ?>
              <option value="<?= e($dr['id']) ?>" <?= $r['discord_role_id'] === $dr['id'] ? 'selected' : '' ?>><?= e($dr['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php else: ?>
          <input type="text" name="discord_role_id" value="<?= e($r['discord_role_id']) ?>" placeholder="Discord Rollen-ID">
          <?php endif; ?>
        </div>
        <div class="btn-row">
          <button class="btn small" type="submit">Speichern</button>
          <button class="btn danger small" type="submit" name="action" value="delete" data-confirm="Rang wirklich löschen?">Löschen</button>
        </div>
      </form>
    </details>
  </div>
  <?php endforeach; ?>
</div>

<div class="card" style="max-width:480px;">
  <h2>Neuer Rang</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <div class="form-row">
      <div class="field"><label>Name</label><input type="text" name="name" required></div>
      <div class="field"><label>Stufe</label><input type="text" name="level" value="0" required></div>
    </div>
    <div class="field"><label>Farbe</label><input type="text" name="color" value="#5865F2"></div>
    <div class="field">
      <label>Berechtigungen</label>
      <?php foreach ($allPerms as $key => $label): ?>
        <label style="font-weight:400;display:flex;align-items:center;gap:8px;margin-bottom:4px;">
          <input type="checkbox" name="permissions[]" value="<?= e($key) ?>" style="width:auto;">
          <?= e($label) ?>
        </label>
      <?php endforeach; ?>
    </div>
    <button class="btn" type="submit">Rang erstellen</button>
  </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
