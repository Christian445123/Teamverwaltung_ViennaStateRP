<?php
require __DIR__ . '/../bootstrap.php';
$user = Auth::requireLogin();
$db = DB::get();
$canManage = Perm::has($user, 'meetings.manage');

$upcoming = $db->query("
  SELECT m.*, t.name AS team_name,
    (SELECT status FROM meeting_attendees WHERE meeting_id = m.id AND user_id = " . (int) $user['id'] . ") AS my_status
  FROM meetings m LEFT JOIN teams t ON t.id = m.team_id
  WHERE m.start_time >= datetime('now') AND m.status != 'cancelled'
  ORDER BY m.start_time ASC
")->fetchAll();

$past = $db->query("
  SELECT m.*, t.name AS team_name,
    (SELECT status FROM meeting_attendees WHERE meeting_id = m.id AND user_id = " . (int) $user['id'] . ") AS my_status
  FROM meetings m LEFT JOIN teams t ON t.id = m.team_id
  WHERE m.start_time < datetime('now') OR m.status = 'cancelled'
  ORDER BY m.start_time DESC LIMIT 20
")->fetchAll();

function render_meeting_row(array $m): void {
    $ts = strtotime($m['start_time']);
    $labels = ['accepted' => 'Zugesagt', 'declined' => 'Abgesagt', 'maybe' => 'Vielleicht', 'pending' => 'Offen'];
    ?>
    <div class="meeting-list-item">
      <div class="meeting-main">
        <div class="meeting-date-box">
          <div class="day"><?= date('d', $ts) ?></div>
          <div class="month"><?= date('M', $ts) ?></div>
        </div>
        <div>
          <a href="<?= url('meeting_view.php?id=' . $m['id']) ?>"><strong><?= e($m['title']) ?></strong></a>
          <div class="card-sub"><?= date('H:i', $ts) ?> Uhr<?= $m['location'] ? ' · ' . e($m['location']) : '' ?><?= $m['team_name'] ? ' · ' . e($m['team_name']) : '' ?></div>
        </div>
      </div>
      <div class="btn-row" style="align-items:center;">
        <?php if ($m['status'] === 'cancelled'): ?>
          <span class="badge status-cancelled">Abgesagt</span>
        <?php else: ?>
          <span class="badge rsvp-<?= e($m['my_status'] ?: 'pending') ?>"><?= $labels[$m['my_status'] ?: 'pending'] ?></span>
        <?php endif; ?>
      </div>
    </div>
    <?php
}

$pageTitle = 'Besprechungen';
$active = 'meetings';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <h1>Besprechungen</h1>
  <?php if ($canManage): ?><a href="<?= url('meeting_form.php') ?>" class="btn">+ Besprechung erstellen</a><?php endif; ?>
</div>

<div class="card">
  <h2>Anstehend</h2>
  <?php if (!$upcoming): ?>
    <div class="empty-state">Keine anstehenden Besprechungen.</div>
  <?php else: foreach ($upcoming as $m) render_meeting_row($m); endif; ?>
</div>

<div class="card">
  <h2>Vergangen &amp; abgesagt</h2>
  <?php if (!$past): ?>
    <div class="empty-state">Noch keine vergangenen Besprechungen.</div>
  <?php else: foreach ($past as $m) render_meeting_row($m); endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
