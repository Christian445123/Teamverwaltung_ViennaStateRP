<?php
require __DIR__ . '/bootstrap.php';
$user = Auth::requirePermission('meetings.manage');
$db = DB::get();

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$meeting = null;
$currentAttendeeIds = [];
if ($id) {
    $stmt = $db->prepare("SELECT * FROM meetings WHERE id = ?");
    $stmt->execute([$id]);
    $meeting = $stmt->fetch();
    if (!$meeting) {
        flash('error', 'Besprechung nicht gefunden.');
        redirect(url('meetings.php'));
    }
    $attStmt = $db->prepare("SELECT user_id FROM meeting_attendees WHERE meeting_id = ?");
    $attStmt->execute([$id]);
    $currentAttendeeIds = $attStmt->fetchAll(PDO::FETCH_COLUMN);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'delete' && $meeting) {
        if ($meeting['discord_event_id']) {
            DiscordClient::deleteScheduledEvent($meeting['discord_event_id']);
        }
        $db->prepare("DELETE FROM meetings WHERE id = ?")->execute([$meeting['id']]);
        flash('success', 'Besprechung gelöscht.');
        redirect(url('meetings.php'));
    }

    if ($action === 'cancel' && $meeting) {
        $db->prepare("UPDATE meetings SET status = 'cancelled' WHERE id = ?")->execute([$meeting['id']]);
        $meeting['status'] = 'cancelled';
        if ($meeting['discord_event_id']) {
            DiscordClient::updateScheduledEvent($meeting['discord_event_id'], $meeting);
        }
        if ($meeting['discord_message_id']) {
            DiscordClient::updateMeetingAnnouncement($meeting);
        }
        flash('success', 'Besprechung abgesagt.');
        redirect(url('meeting_view.php?id=' . $meeting['id']));
    }

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $startTime = $_POST['start_time'] ?? '';
    $endTime = $_POST['end_time'] ?? '';
    $teamId = $_POST['team_id'] !== '' ? (int) $_POST['team_id'] : null;
    $announce = !empty($_POST['announce_discord']);
    $createEvent = !empty($_POST['create_discord_event']);
    $selectedAttendeeIds = array_map('intval', $_POST['attendee_ids'] ?? []);

    if ($title === '' || !strtotime($startTime)) {
        flash('error', 'Titel und Startzeit sind erforderlich.');
        redirect(url('meeting_form.php') . ($id ? '?id=' . $id : ''));
    }

    $startTimeSql = date('Y-m-d H:i:s', strtotime($startTime));
    $endTimeSql = $endTime && strtotime($endTime) ? date('Y-m-d H:i:s', strtotime($endTime)) : null;

    $data = [
        'title' => $title, 'description' => $description, 'location' => $location,
        'start_time' => $startTimeSql, 'end_time' => $endTimeSql,
    ];

    if ($meeting) {
        $stmt = $db->prepare("UPDATE meetings SET title=?, description=?, location=?, start_time=?, end_time=?, team_id=? WHERE id=?");
        $stmt->execute([$title, $description ?: null, $location ?: null, $startTimeSql, $endTimeSql, $teamId, $meeting['id']]);
        $meetingId = $meeting['id'];

        // Teilnehmerliste an die Checkbox-Auswahl anpassen: neu Angehakte ergänzen, wieder
        // abgewählte samt ihrer Zu-/Absage entfernen (FK ON DELETE CASCADE).
        $toRemove = array_diff($currentAttendeeIds, $selectedAttendeeIds);
        $toAdd = array_diff($selectedAttendeeIds, $currentAttendeeIds);
        if ($toRemove) {
            $placeholders = implode(',', array_fill(0, count($toRemove), '?'));
            $db->prepare("DELETE FROM meeting_attendees WHERE meeting_id = ? AND user_id IN ({$placeholders})")
                ->execute(array_merge([$meetingId], array_values($toRemove)));
        }
        if ($toAdd) {
            $insertAttendee = $db->prepare("INSERT IGNORE INTO meeting_attendees (meeting_id, user_id, status) VALUES (?, ?, 'pending')");
            foreach ($toAdd as $uid) {
                $insertAttendee->execute([$meetingId, $uid]);
            }
        }

        if ($meeting['discord_event_id']) {
            $data['status'] = $meeting['status'];
            DiscordClient::updateScheduledEvent($meeting['discord_event_id'], $data);
        }
        flash('success', 'Besprechung aktualisiert.');
    } else {
        $stmt = $db->prepare("INSERT INTO meetings (title, description, location, start_time, end_time, team_id, created_by, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'scheduled')");
        $stmt->execute([$title, $description ?: null, $location ?: null, $startTimeSql, $endTimeSql, $teamId, $user['id']]);
        $meetingId = $db->lastInsertId();

        $insertAttendee = $db->prepare("INSERT IGNORE INTO meeting_attendees (meeting_id, user_id, status) VALUES (?, ?, 'pending')");
        foreach ($selectedAttendeeIds as $uid) {
            $insertAttendee->execute([$meetingId, $uid]);
        }

        $discordEventId = null;
        if ($createEvent && Settings::isBotConfigured()) {
            $discordEventId = DiscordClient::createScheduledEvent($data);
        }
        $discordMessageId = null;
        $discordChannelId = null;
        if ($announce) {
            $posted = DiscordClient::announceMeeting($data + ['id' => $meetingId]);
            if ($posted) {
                $discordMessageId = $posted['message_id'];
                $discordChannelId = $posted['channel_id'];
            }
        }
        if ($discordEventId || $discordMessageId) {
            $db->prepare("UPDATE meetings SET discord_event_id=?, discord_message_id=?, discord_channel_id=? WHERE id=?")
               ->execute([$discordEventId, $discordMessageId, $discordChannelId, $meetingId]);
        }
        flash('success', 'Besprechung erstellt.');
    }

    redirect(url('meeting_view.php?id=' . $meetingId));
}

$teams = $db->query("SELECT * FROM teams ORDER BY name ASC")->fetchAll();
$members = $db->query("
    SELECT u.id, u.display_name, r.name AS rank_name
    FROM users u
    LEFT JOIN ranks r ON r.id = u.rank_id
    WHERE u.status = 'active'
    ORDER BY u.display_name ASC
")->fetchAll();
// Neue Besprechung: alle vorauswählen (Admin wählt gezielt ab). Bestehende: aktuelle Teilnehmerliste.
$preselected = $meeting ? $currentAttendeeIds : array_column($members, 'id');

$pageTitle = $meeting ? 'Besprechung bearbeiten' : 'Besprechung erstellen';
$active = 'meetings';
require __DIR__ . '/includes/header.php';
?>
<div class="page-header"><h1><?= e($pageTitle) ?></h1></div>

<div class="card" style="max-width:640px;">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <div class="field">
      <label>Titel *</label>
      <input type="text" name="title" value="<?= e($meeting['title'] ?? '') ?>" required>
    </div>
    <div class="field">
      <label>Beschreibung</label>
      <textarea name="description"><?= e($meeting['description'] ?? '') ?></textarea>
    </div>
    <div class="form-row">
      <div class="field">
        <label>Beginn *</label>
        <input type="datetime-local" name="start_time" value="<?= e(input_datetime_value($meeting['start_time'] ?? null)) ?>" required>
      </div>
      <div class="field">
        <label>Ende</label>
        <input type="datetime-local" name="end_time" value="<?= e(input_datetime_value($meeting['end_time'] ?? null)) ?>">
      </div>
    </div>
    <div class="form-row">
      <div class="field">
        <label>Ort / Link (z. B. Discord-Voice-Channel)</label>
        <input type="text" name="location" value="<?= e($meeting['location'] ?? '') ?>">
      </div>
      <div class="field">
        <label>Team (nur für Discord-Rollen-Ping bei der Ankündigung)</label>
        <select name="team_id">
          <option value="">– keins –</option>
          <?php foreach ($teams as $t): ?>
            <option value="<?= $t['id'] ?>" <?= ($meeting['team_id'] ?? null) == $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="field">
      <label>Teilnehmer</label>
      <div style="display:flex;flex-direction:column;gap:4px;max-height:260px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius);padding:8px 10px;">
        <?php if (!$members): ?>
          <p class="text-muted" style="margin:0;">Keine aktiven Mitglieder vorhanden.</p>
        <?php endif; ?>
        <?php foreach ($members as $m): ?>
          <label style="font-weight:400;display:flex;align-items:center;gap:8px;margin-bottom:0;">
            <input type="checkbox" name="attendee_ids[]" value="<?= $m['id'] ?>" style="width:auto;" <?= in_array($m['id'], $preselected, true) ? 'checked' : '' ?>>
            <?= e($m['display_name']) ?>
            <?php if ($m['rank_name']): ?><span class="text-muted" style="font-size:12px;">(<?= e($m['rank_name']) ?>)</span><?php endif; ?>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="field-hint">Einzeln an-/abwählbar, unabhängig vom Team oben.</div>
    </div>

    <?php if (!$meeting): ?>
    <div class="field">
      <label style="font-weight:400;display:flex;align-items:center;gap:8px;">
        <input type="checkbox" name="announce_discord" value="1" style="width:auto;" <?= Settings::isDiscordConfigured() ? 'checked' : 'disabled' ?>>
        In Discord ankündigen <?= Settings::isDiscordConfigured() ? '' : '(Discord nicht konfiguriert)' ?>
      </label>
      <label style="font-weight:400;display:flex;align-items:center;gap:8px;">
        <input type="checkbox" name="create_discord_event" value="1" style="width:auto;" <?= Settings::isBotConfigured() ? 'checked' : 'disabled' ?>>
        Discord-Event erstellen <?= Settings::isBotConfigured() ? '' : '(Bot nicht konfiguriert)' ?>
      </label>
    </div>
    <?php endif; ?>

    <div class="btn-row">
      <button class="btn" type="submit">Speichern</button>
      <a href="<?= url('meetings.php') ?>" class="btn secondary">Abbrechen</a>
    </div>
  </form>
</div>

<?php if ($meeting): ?>
<div class="card" style="max-width:640px;border-color:var(--danger);">
  <h2>Gefahrenzone</h2>
  <div class="btn-row">
    <form method="post" data-confirm="Besprechung wirklich absagen?">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="cancel">
      <button class="btn secondary" type="submit">Absagen</button>
    </form>
    <form method="post" data-confirm="Besprechung wirklich endgültig löschen?">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <button class="btn danger" type="submit">Löschen</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
