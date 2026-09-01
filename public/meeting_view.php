<?php
require __DIR__ . '/../bootstrap.php';
$user = Auth::requireLogin();
$db = DB::get();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT m.*, t.name AS team_name FROM meetings m LEFT JOIN teams t ON t.id = m.team_id WHERE m.id = ?");
$stmt->execute([$id]);
$meeting = $stmt->fetch();
if (!$meeting) {
    flash('error', 'Besprechung nicht gefunden.');
    redirect(url('meetings.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $status = $_POST['rsvp_status'] ?? '';
    if (in_array($status, ['accepted', 'declined', 'maybe'], true)) {
        $stmt = $db->prepare("INSERT INTO meeting_attendees (meeting_id, user_id, status, responded_at) VALUES (?, ?, ?, datetime('now'))
            ON CONFLICT(meeting_id, user_id) DO UPDATE SET status = excluded.status, responded_at = excluded.responded_at");
        $stmt->execute([$id, $user['id'], $status]);
        flash('success', 'Rückmeldung gespeichert.');
    }
    redirect(url('meeting_view.php?id=' . $id));
}

$myStatusStmt = $db->prepare("SELECT status FROM meeting_attendees WHERE meeting_id = ? AND user_id = ?");
$myStatusStmt->execute([$id, $user['id']]);
$myStatus = $myStatusStmt->fetchColumn() ?: 'pending';

$attendees = $db->query("
  SELECT u.id, u.display_name, u.discord_id, u.discord_avatar, ma.status
  FROM meeting_attendees ma JOIN users u ON u.id = ma.user_id
  WHERE ma.meeting_id = " . (int) $id . "
  ORDER BY ma.status ASC, u.display_name ASC
")->fetchAll();

$canManage = Perm::has($user, 'meetings.manage');
$labels = ['accepted' => 'Zugesagt', 'declined' => 'Abgesagt', 'maybe' => 'Vielleicht', 'pending' => 'Offen'];

$pageTitle = $meeting['title'];
$active = 'meetings';
require __DIR__ . '/../includes/header.php';
?>
<div class="page-header">
  <h1><?= e($meeting['title']) ?> <?php if ($meeting['status'] === 'cancelled'): ?><span class="badge status-cancelled">Abgesagt</span><?php endif; ?></h1>
  <?php if ($canManage): ?><a href="<?= url('meeting_form.php?id=' . $meeting['id']) ?>" class="btn secondary">Bearbeiten</a><?php endif; ?>
</div>

<div class="grid grid-2">
  <div class="card">
    <h2>Details</h2>
    <p><strong>Beginn:</strong> <?= e(fmt_datetime($meeting['start_time'])) ?> Uhr</p>
    <?php if ($meeting['end_time']): ?><p><strong>Ende:</strong> <?= e(fmt_datetime($meeting['end_time'])) ?> Uhr</p><?php endif; ?>
    <?php if ($meeting['location']): ?><p><strong>Ort:</strong> <?= e($meeting['location']) ?></p><?php endif; ?>
    <?php if ($meeting['team_name']): ?><p><strong>Team:</strong> <?= e($meeting['team_name']) ?></p><?php endif; ?>
    <?php if ($meeting['description']): ?><p style="white-space:pre-wrap;"><?= e($meeting['description']) ?></p><?php endif; ?>
    <?php if ($meeting['discord_event_id']): ?><p class="text-muted" style="font-size:12px;">✓ Discord-Event verknüpft</p><?php endif; ?>
  </div>

  <div class="card">
    <h2>Deine Rückmeldung</h2>
    <?php if ($meeting['status'] === 'cancelled'): ?>
      <p class="text-muted">Diese Besprechung wurde abgesagt.</p>
    <?php else: ?>
      <p>Aktueller Status: <span class="badge rsvp-<?= e($myStatus) ?>"><?= $labels[$myStatus] ?></span></p>
      <form method="post" class="btn-row">
        <?= csrf_field() ?>
        <button class="btn" type="submit" name="rsvp_status" value="accepted">Zusagen</button>
        <button class="btn secondary" type="submit" name="rsvp_status" value="maybe">Vielleicht</button>
        <button class="btn danger" type="submit" name="rsvp_status" value="declined">Absagen</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h2>Teilnehmer (<?= count($attendees) ?>)</h2>
  <div class="attendee-list">
    <?php foreach ($attendees as $a): ?>
      <div class="attendee-chip">
        <?php if ($a['discord_id']): ?>
          <img class="avatar" style="width:20px;height:20px;" src="<?= e(DiscordClient::avatarUrl($a['discord_id'], $a['discord_avatar'])) ?>" alt="">
        <?php endif; ?>
        <?= e($a['display_name']) ?>
        <span class="badge rsvp-<?= e($a['status']) ?>" style="padding:2px 8px;font-size:11px;"><?= $labels[$a['status']] ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
