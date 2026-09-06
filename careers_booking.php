<?php
require __DIR__ . '/bootstrap.php';
// Bewusst kein Login nötig — Terminbuchung per unratbarem Token aus dem Buchungslink.
$db = DB::get();

$token = $_GET['token'] ?? ($_POST['token'] ?? '');
$stmt = $db->prepare("SELECT a.*, jp.title AS posting_title, jp.id AS posting_id FROM applications a JOIN job_postings jp ON jp.id = a.posting_id WHERE a.booking_token = ?");
$stmt->execute([$token]);
$application = $stmt->fetch();

if (!$application) {
    $pageTitle = 'Ungültiger Link';
    require __DIR__ . '/includes/careers_header.php';
    ?>
    <div class="careers-card-form">
      <h2 style="margin-top:0;">Ungültiger Link</h2>
      <p style="color:var(--c-text-muted);">Dieser Buchungslink ist nicht gültig. Bitte prüfe, ob du ihn vollständig kopiert hast.</p>
    </div>
    <?php require __DIR__ . '/includes/careers_footer.php'; exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'book' && $application['status'] === 'interview_invited') {
        $slotId = (int) ($_POST['slot_id'] ?? 0);
        $stmt = $db->prepare("UPDATE interview_slots SET application_id = ? WHERE id = ? AND posting_id = ? AND application_id IS NULL");
        $stmt->execute([$application['id'], $slotId, $application['posting_id']]);
        if ($stmt->rowCount() > 0) {
            $db->prepare("UPDATE applications SET status = 'interview_scheduled' WHERE id = ?")->execute([$application['id']]);
            audit_log('application.interview_booked', "{$application['applicant_name']} → {$application['posting_title']}", $application['applicant_name']);
            flash('success', 'Termin gebucht! Wir sehen uns dann.');
        } else {
            flash('error', 'Dieser Termin wurde gerade eben von jemand anderem gebucht. Bitte wähle einen anderen.');
        }
        redirect(url('careers_booking.php?token=' . urlencode($token)));
    }

    if ($action === 'release' && $application['status'] === 'interview_scheduled') {
        $db->prepare("UPDATE interview_slots SET application_id = NULL WHERE application_id = ?")->execute([$application['id']]);
        $db->prepare("UPDATE applications SET status = 'interview_invited' WHERE id = ?")->execute([$application['id']]);
        flash('success', 'Termin freigegeben. Wähle unten einen neuen aus.');
        redirect(url('careers_booking.php?token=' . urlencode($token)));
    }
}

// Nach evtl. Statusänderung oben aktuellen Stand neu laden.
$stmt = $db->prepare("SELECT a.*, jp.title AS posting_title, jp.id AS posting_id FROM applications a JOIN job_postings jp ON jp.id = a.posting_id WHERE a.booking_token = ?");
$stmt->execute([$token]);
$application = $stmt->fetch();

$openSlots = [];
$bookedSlot = null;
if ($application['status'] === 'interview_invited') {
    $stmt = $db->prepare("SELECT * FROM interview_slots WHERE posting_id = ? AND application_id IS NULL AND start_time > NOW() ORDER BY start_time ASC");
    $stmt->execute([$application['posting_id']]);
    $openSlots = $stmt->fetchAll();
} elseif ($application['status'] === 'interview_scheduled') {
    $stmt = $db->prepare("SELECT * FROM interview_slots WHERE application_id = ?");
    $stmt->execute([$application['id']]);
    $bookedSlot = $stmt->fetch();
}

$pageTitle = 'Terminbuchung';
require __DIR__ . '/includes/careers_header.php';
?>
<div class="careers-detail-header"><h1>Terminbuchung: <?= e($application['posting_title']) ?></h1></div>

<div class="careers-card-form">
  <p>Hallo <?= e($application['applicant_name']) ?>,</p>

  <?php if ($application['status'] === 'pending'): ?>
    <p>deine Bewerbung wird noch geprüft. Sobald wir dich zu einem Gespräch einladen möchten, kannst du hier deinen Wunschtermin wählen.</p>

  <?php elseif ($application['status'] === 'rejected'): ?>
    <p>vielen Dank für dein Interesse — leider können wir dir aktuell keinen Platz anbieten.</p>

  <?php elseif ($application['status'] === 'accepted'): ?>
    <p>deine Bewerbung wurde bereits angenommen — ein Termin ist hier nicht mehr nötig. Wir freuen uns auf dich!</p>

  <?php elseif ($application['status'] === 'interview_scheduled' && $bookedSlot): ?>
    <p>dein Bewerbungsgespräch ist terminiert für:</p>
    <p style="font-size:18px;"><strong><?= e(fmt_datetime($bookedSlot['start_time'])) ?> Uhr</strong></p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <input type="hidden" name="action" value="release">
      <button class="careers-btn-outline" type="submit">Anderen Termin wählen</button>
    </form>

  <?php elseif ($application['status'] === 'interview_invited'): ?>
    <p>bitte wähle einen der folgenden freien Termine für dein Bewerbungsgespräch:</p>
    <?php if (!$openSlots): ?>
      <div class="careers-empty">Aktuell sind keine freien Termine verfügbar. Bitte wende dich an das Team.</div>
    <?php else: ?>
    <div class="careers-slot-list">
      <?php foreach ($openSlots as $s): ?>
        <div class="careers-slot-item">
          <div><strong><?= e(fmt_datetime($s['start_time'])) ?> Uhr</strong></div>
          <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <input type="hidden" name="action" value="book">
            <input type="hidden" name="slot_id" value="<?= $s['id'] ?>">
            <button class="careers-btn-primary" type="submit">Wählen</button>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/careers_footer.php'; ?>
