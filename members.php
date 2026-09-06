<?php
require __DIR__ . '/bootstrap.php';
$user = Auth::requireLogin();
$db = DB::get();
$canManage = Perm::has($user, 'members.manage');
$canViewAttendance = Perm::has($user, 'meetings.view_attendance');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'set_rank') {
        $memberId = (int) ($_POST['member_id'] ?? 0);
        $newRankId = $_POST['rank_id'] !== '' ? (int) $_POST['rank_id'] : null;

        $stmt = $db->prepare("SELECT u.*, r.name AS rank_name, r.level AS rank_level FROM users u LEFT JOIN ranks r ON r.id = u.rank_id WHERE u.id = ?");
        $stmt->execute([$memberId]);
        $member = $stmt->fetch();
        $oldRankId = $member && $member['rank_id'] !== null ? (int) $member['rank_id'] : null;

        if ($member && $newRankId !== $oldRankId) {
            $newRank = null;
            if ($newRankId) {
                $rankStmt = $db->prepare("SELECT * FROM ranks WHERE id = ?");
                $rankStmt->execute([$newRankId]);
                $newRank = $rankStmt->fetch();
            }

            $db->prepare("UPDATE users SET rank_id = ? WHERE id = ?")->execute([$newRankId, $memberId]);

            $oldLabel = $member['rank_name'] ?: 'kein Rang';
            $newLabel = $newRank['name'] ?? 'kein Rang';
            $oldLevel = (int) ($member['rank_level'] ?? -1);
            $newLevel = (int) ($newRank['level'] ?? -1);
            if ($newRank && $oldLevel >= 0 && $newLevel > $oldLevel) {
                $actionSlug = 'member.promote';
                $emoji = '🎉';
                $verb = 'befördert';
            } elseif ($newLevel < $oldLevel || !$newRank) {
                $actionSlug = 'member.demote';
                $emoji = '⬇️';
                $verb = 'degradiert';
            } else {
                $actionSlug = 'member.rank_change';
                $emoji = '🔄';
                $verb = 'umgestuft';
            }
            audit_log($actionSlug, "{$emoji} {$member['display_name']} wurde von \"{$oldLabel}\" zu \"{$newLabel}\" {$verb}.");

            // Discord-Rolle automatisch anhand der neuen Rang-Zuordnung setzen (ranks.discord_role_id,
            // unter Ränge festgelegt) — nur wenn Discord verknüpft & konfiguriert ist, gleiches
            // Verhalten wie beim Speichern im vollen Mitglied-Formular.
            if ($member['discord_id'] && Settings::isBotConfigured()) {
                $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$memberId]);
                DiscordClient::syncRolesForUser($stmt->fetch());
            }

            flash('success', "{$member['display_name']}: \"{$oldLabel}\" → \"{$newLabel}\".");
        }
        redirect(url('members.php'));
    }
}

$members = $db->query("
  SELECT u.*, r.name AS rank_name, r.color AS rank_color, r.level AS rank_level,
    GROUP_CONCAT(t.name ORDER BY t.name SEPARATOR ', ') AS team_names,
    (SELECT COUNT(*) FROM meeting_attendees ma WHERE ma.user_id = u.id AND ma.attended IS NOT NULL) AS attendance_total,
    (SELECT SUM(ma.attended) FROM meeting_attendees ma WHERE ma.user_id = u.id AND ma.attended IS NOT NULL) AS attendance_present
  FROM users u
  LEFT JOIN ranks r ON r.id = u.rank_id
  LEFT JOIN user_teams ut ON ut.user_id = u.id
  LEFT JOIN teams t ON t.id = ut.team_id
  WHERE u.status = 'active'
  GROUP BY u.id
  ORDER BY r.level DESC, u.display_name ASC
")->fetchAll();

$ranks = $db->query("SELECT * FROM ranks ORDER BY level DESC")->fetchAll();

$pageTitle = 'Mitglieder';
$active = 'members';
require __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <h1>Mitglieder</h1>
  <?php if ($canManage): ?>
    <a href="<?= url('member_form.php') ?>" class="btn">+ Mitglied hinzufügen</a>
  <?php endif; ?>
</div>

<div class="card">
  <div class="table-wrap">
  <table>
    <thead>
      <tr>
        <th>Name</th>
        <th>Rang</th>
        <th>Team</th>
        <th>Discord</th>
        <?php if ($canViewAttendance): ?><th>Anwesenheit</th><?php endif; ?>
        <?php if ($canManage): ?><th></th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($members as $m): ?>
      <tr>
        <td>
          <div style="display:flex;align-items:center;gap:10px;">
            <?php if ($m['discord_id']): ?>
              <img class="avatar" src="<?= e(DiscordClient::avatarUrl($m['discord_id'], $m['discord_avatar'])) ?>" alt="">
            <?php else: ?>
              <span class="avatar avatar-fallback" style="width:28px;height:28px;"><?= e(mb_substr($m['display_name'], 0, 1)) ?></span>
            <?php endif; ?>
            <strong><?= e($m['display_name']) ?></strong>
            <?php if (!empty($m['is_team'])): ?><span class="badge outline" style="font-size:10px;" title="Team">TEAM</span><?php endif; ?>
            <?php if (!empty($m['is_high_team'])): ?><span class="badge" style="background:#e8b86d;color:#2b2d31;" title="High-Team">★</span><?php endif; ?>
            <?php if (!empty($m['is_banned'])): ?><span class="badge" style="background:var(--danger);" title="<?= e($m['banned_reason'] ?: 'Gesperrt') ?>">🔒 Gesperrt</span><?php endif; ?>
          </div>
        </td>
        <td>
          <?php if ($canManage): ?>
            <form method="post" style="display:inline;">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="set_rank">
              <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
              <select name="rank_id" onchange="this.form.submit()" style="width:auto;padding:4px 8px;font-size:12px;" title="Rang ändern (Beförderung/Degradierung)">
                <option value="">– kein Rang –</option>
                <?php foreach ($ranks as $r): ?>
                  <option value="<?= $r['id'] ?>" <?= (int) $m['rank_id'] === (int) $r['id'] ? 'selected' : '' ?>><?= e($r['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </form>
          <?php elseif ($m['rank_name']): ?>
            <span class="badge" style="background:<?= e($m['rank_color']) ?>"><?= e($m['rank_name']) ?></span>
          <?php else: ?>
            <span class="text-muted">–</span>
          <?php endif; ?>
        </td>
        <td><?= $m['team_names'] ? e($m['team_names']) : '<span class="text-muted">–</span>' ?></td>
        <td>
          <?php if ($m['discord_id']): ?>
            <span class="badge outline">✓ <?= e($m['discord_username']) ?></span>
          <?php else: ?>
            <span class="text-muted">nicht verknüpft</span>
          <?php endif; ?>
        </td>
        <?php if ($canViewAttendance): ?>
        <td>
          <?php if ((int) $m['attendance_total'] > 0): ?>
            <?php $pct = round((int) $m['attendance_present'] / (int) $m['attendance_total'] * 100); ?>
            <span title="<?= (int) $m['attendance_present'] ?> von <?= (int) $m['attendance_total'] ?> erfassten Besprechungen anwesend"><?= (int) $m['attendance_present'] ?>/<?= (int) $m['attendance_total'] ?> (<?= $pct ?>%)</span>
          <?php else: ?>
            <span class="text-muted">keine Daten</span>
          <?php endif; ?>
        </td>
        <?php endif; ?>
        <?php if ($canManage): ?>
        <td>
          <div class="btn-row">
            <a href="<?= url('member_form.php?id=' . $m['id']) ?>" class="btn secondary small">Bearbeiten</a>
          </div>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
