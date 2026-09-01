<?php
require __DIR__ . '/../bootstrap.php';
$user = Auth::requireLogin();
$db = DB::get();

$memberCount = (int) $db->query("SELECT COUNT(*) c FROM users WHERE status = 'active'")->fetch()['c'];
$teamCount = (int) $db->query("SELECT COUNT(*) c FROM teams")->fetch()['c'];
$upcomingCount = (int) $db->query("SELECT COUNT(*) c FROM meetings WHERE status = 'scheduled' AND start_time >= NOW()")->fetch()['c'];
$linkedCount = (int) $db->query("SELECT COUNT(*) c FROM users WHERE status = 'active' AND discord_id IS NOT NULL")->fetch()['c'];

$upcoming = $db->query("
  SELECT m.*, t.name AS team_name,
    (SELECT status FROM meeting_attendees WHERE meeting_id = m.id AND user_id = " . (int) $user['id'] . ") AS my_status
  FROM meetings m
  LEFT JOIN teams t ON t.id = m.team_id
  WHERE m.status = 'scheduled' AND m.start_time >= NOW()
  ORDER BY m.start_time ASC LIMIT 6
")->fetchAll();

$pageTitle = 'Dashboard';
$active = 'dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <h1>Willkommen, <?= e($user['display_name']) ?></h1>
</div>

<div class="grid grid-3" style="margin-bottom:24px;">
  <div class="stat"><div class="num"><?= $memberCount ?></div><div class="label">Aktive Mitglieder</div></div>
  <div class="stat"><div class="num"><?= $teamCount ?></div><div class="label">Teams</div></div>
  <div class="stat"><div class="num"><?= $upcomingCount ?></div><div class="label">Anstehende Besprechungen</div></div>
  <div class="stat"><div class="num"><?= $linkedCount ?>/<?= $memberCount ?></div><div class="label">Mit Discord verknüpft</div></div>
</div>

<div class="card">
  <div class="page-header" style="margin-bottom:14px;">
    <h2 class="mt-0 mb-0">Nächste Besprechungen</h2>
    <a href="<?= url('meetings.php') ?>" class="btn secondary small">Alle anzeigen</a>
  </div>
  <?php if (!$upcoming): ?>
    <div class="empty-state">Aktuell sind keine Besprechungen geplant.
      <?php if (Perm::has($user, 'meetings.manage')): ?>
        <br><a href="<?= url('meeting_form.php') ?>" class="btn small" style="margin-top:12px;">Besprechung erstellen</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <?php foreach ($upcoming as $m): $ts = strtotime($m['start_time']); ?>
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
        <span class="badge rsvp-<?= e($m['my_status'] ?: 'pending') ?>"><?php
          $labels = ['accepted' => 'Zugesagt', 'declined' => 'Abgesagt', 'maybe' => 'Vielleicht', 'pending' => 'Offen'];
          echo $labels[$m['my_status'] ?: 'pending'];
        ?></span>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php if (!empty($user['is_superadmin']) && !Settings::isDiscordConfigured()): ?>
<div class="card" style="border-color:var(--accent);">
  <h3 class="mt-0">Discord noch nicht verbunden</h3>
  <p class="text-muted">Verbinde deinen Discord-Server, um Login, Rollen-Synchronisierung und Event-Ankündigungen zu aktivieren.</p>
  <a href="<?= url('settings.php') ?>" class="btn discord">Jetzt einrichten</a>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
