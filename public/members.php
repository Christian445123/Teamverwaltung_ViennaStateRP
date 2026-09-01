<?php
require __DIR__ . '/../bootstrap.php';
$user = Auth::requireLogin();
$db = DB::get();
$canManage = Perm::has($user, 'members.manage');

$members = $db->query("
  SELECT u.*, r.name AS rank_name, r.color AS rank_color, r.level AS rank_level, t.name AS team_name
  FROM users u
  LEFT JOIN ranks r ON r.id = u.rank_id
  LEFT JOIN teams t ON t.id = u.team_id
  WHERE u.status = 'active'
  ORDER BY r.level DESC, u.display_name ASC
")->fetchAll();

$pageTitle = 'Mitglieder';
$active = 'members';
require __DIR__ . '/../includes/header.php';
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
          </div>
        </td>
        <td><?php if ($m['rank_name']): ?><span class="badge" style="background:<?= e($m['rank_color']) ?>"><?= e($m['rank_name']) ?></span><?php else: ?><span class="text-muted">–</span><?php endif; ?></td>
        <td><?= $m['team_name'] ? e($m['team_name']) : '<span class="text-muted">–</span>' ?></td>
        <td>
          <?php if ($m['discord_id']): ?>
            <span class="badge outline">✓ <?= e($m['discord_username']) ?></span>
          <?php else: ?>
            <span class="text-muted">nicht verknüpft</span>
          <?php endif; ?>
        </td>
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
<?php require __DIR__ . '/../includes/footer.php'; ?>
