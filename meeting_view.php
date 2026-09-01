<?php
require __DIR__ . '/bootstrap.php';
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

$canRespond = Perm::has($user, 'meetings.respond');
$canManage = Perm::has($user, 'meetings.manage');
$canViewAttendance = Perm::has($user, 'meetings.view_attendance');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'rsvp';

    if ($action === 'mark_attendance') {
        if (!$canManage) {
            flash('error', 'Dir fehlt die Berechtigung, Anwesenheit zu erfassen.');
            redirect(url('meeting_view.php?id=' . $id));
        }
        $attendeeId = (int) ($_POST['attendee_id'] ?? 0);
        $attended = $_POST['attended'] ?? '';
        if (in_array($attended, ['1', '0', ''], true)) {
            $stmt = $db->prepare("UPDATE meeting_attendees SET attended = ? WHERE meeting_id = ? AND user_id = ?");
            $stmt->execute([$attended === '' ? null : (int) $attended, $id, $attendeeId]);
            flash('success', 'Anwesenheit aktualisiert.');
        }
        redirect(url('meeting_view.php?id=' . $id));
    }

    if (!$canRespond) {
        flash('error', 'Dir fehlt die Berechtigung, auf Besprechungen zu antworten.');
        redirect(url('meeting_view.php?id=' . $id));
    }
    $status = $_POST['rsvp_status'] ?? '';
    if (in_array($status, ['accepted', 'declined', 'maybe'], true)) {
        $stmt = $db->prepare("INSERT INTO meeting_attendees (meeting_id, user_id, status, responded_at) VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE status = VALUES(status), responded_at = VALUES(responded_at)");
        $stmt->execute([$id, $user['id'], $status]);
        flash('success', 'Rückmeldung gespeichert.');
    }
    redirect(url('meeting_view.php?id=' . $id));
}

$myStatusStmt = $db->prepare("SELECT status FROM meeting_attendees WHERE meeting_id = ? AND user_id = ?");
$myStatusStmt->execute([$id, $user['id']]);
$myStatus = $myStatusStmt->fetchColumn() ?: 'pending';

$attendees = [];
if ($canViewAttendance) {
    $attendees = $db->query("
      SELECT u.id, u.display_name, u.discord_id, u.discord_avatar, ma.status, ma.attended
      FROM meeting_attendees ma JOIN users u ON u.id = ma.user_id
      WHERE ma.meeting_id = " . (int) $id . "
      ORDER BY ma.status ASC, u.display_name ASC
    ")->fetchAll();
}

$labels = ['accepted' => 'Zugesagt', 'declined' => 'Abgesagt', 'maybe' => 'Vielleicht', 'pending' => 'Offen'];

$pageTitle = $meeting['title'];
$active = 'meetings';
require __DIR__ . '/includes/header.php';
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
    <?php elseif (!$canRespond): ?>
      <p>Aktueller Status: <span class="badge rsvp-<?= e($myStatus) ?>"><?= $labels[$myStatus] ?></span></p>
      <p class="text-muted">Dir fehlt die Berechtigung, auf Besprechungen zu antworten.</p>
    <?php else: ?>
      <p>Aktueller Status: <span class="badge rsvp-<?= e($myStatus) ?>"><?= $labels[$myStatus] ?></span></p>
      <form method="post" class="btn-row">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="rsvp">
        <button class="btn" type="submit" name="rsvp_status" value="accepted">Zusagen</button>
        <button class="btn secondary" type="submit" name="rsvp_status" value="maybe">Vielleicht</button>
        <button class="btn danger" type="submit" name="rsvp_status" value="declined">Absagen</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($canViewAttendance): ?>
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
        <?php if ($canManage): ?>
          <?php $attendedColor = $a['attended'] === null ? 'var(--text-muted)' : ($a['attended'] ? 'var(--success)' : 'var(--danger)'); ?>
          <form method="post" style="display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="mark_attendance">
            <input type="hidden" name="attendee_id" value="<?= $a['id'] ?>">
            <select name="attended" onchange="this.form.submit()" style="width:auto;padding:2px 6px;font-size:11px;color:<?= $attendedColor ?>;">
              <option value="" <?= $a['attended'] === null ? 'selected' : '' ?>>Anwesenheit?</option>
              <option value="1" <?= $a['attended'] === 1 ? 'selected' : '' ?>>✓ Anwesend</option>
              <option value="0" <?= $a['attended'] === 0 ? 'selected' : '' ?>>✗ Abwesend</option>
            </select>
          </form>
        <?php elseif ($a['attended'] !== null): ?>
          <span class="badge outline" style="font-size:10px;color:<?= $a['attended'] ? 'var(--success)' : 'var(--danger)' ?>;"><?= $a['attended'] ? '✓ anwesend' : '✗ abwesend' ?></span>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
