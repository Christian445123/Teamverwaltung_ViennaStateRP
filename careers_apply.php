<?php
require __DIR__ . '/bootstrap.php';
// Bewusst kein Login nötig — öffentliche Bewerbungsseite.
$db = DB::get();

$slug = $_GET['job'] ?? ($_POST['job'] ?? '');
$stmt = $db->prepare("SELECT * FROM job_postings WHERE slug = ?");
$stmt->execute([$slug]);
$posting = $stmt->fetch();

if (!$posting || $posting['status'] !== 'open') {
    flash('error', 'Diese Ausschreibung ist nicht mehr verfügbar.');
    redirect(url('careers.php'));
}

$stmt = $db->prepare("SELECT * FROM job_posting_questions WHERE posting_id = ? ORDER BY sort_order ASC, id ASC");
$stmt->execute([$posting['id']]);
$questions = $stmt->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim($_POST['applicant_name'] ?? '');
    $age = trim($_POST['applicant_age'] ?? '');
    $discordTag = trim($_POST['discord_tag'] ?? '');
    $motivation = trim($_POST['motivation'] ?? '');
    $availability = trim($_POST['availability'] ?? '');

    if ($name === '') $errors[] = 'Name ist erforderlich.';
    if ($motivation === '') $errors[] = 'Motivation ist erforderlich.';
    if ($age !== '' && (!ctype_digit($age) || (int) $age < 1 || (int) $age > 120)) $errors[] = 'Alter ist ungültig.';

    $customAnswers = [];
    foreach ($questions as $q) {
        $customAnswers[$q['id']] = trim($_POST['question_' . $q['id']] ?? '');
    }

    if (!$errors) {
        $bookingToken = bin2hex(random_bytes(24));
        $stmt = $db->prepare("INSERT INTO applications (posting_id, applicant_name, applicant_age, discord_tag, motivation, availability, booking_token)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $posting['id'], $name, $age !== '' ? (int) $age : null, $discordTag ?: null, $motivation, $availability ?: null, $bookingToken,
        ]);
        $applicationId = $db->lastInsertId();

        $insertAnswer = $db->prepare("INSERT INTO application_answers (application_id, question_id, answer) VALUES (?, ?, ?)");
        foreach ($customAnswers as $questionId => $answer) {
            if ($answer !== '') {
                $insertAnswer->execute([$applicationId, $questionId, $answer]);
            }
        }

        audit_log('application.submitted', "{$name} → {$posting['title']}", $name);
        DiscordClient::announceNewApplication(
            ['applicant_name' => $name, 'applicant_age' => $age !== '' ? (int) $age : null, 'discord_tag' => $discordTag ?: null],
            $posting['title']
        );
        redirect(url('careers_apply.php?job=' . urlencode($slug) . '&submitted=1'));
    }
}

$submitted = isset($_GET['submitted']);

$pageTitle = 'Bewerbung: ' . $posting['title'];
require __DIR__ . '/includes/careers_header.php';
?>
<a href="<?= url('careers_job.php?job=' . urlencode($slug)) ?>" class="careers-breadcrumb">← <?= e($posting['title']) ?></a>
<div class="careers-detail-header">
  <h1>Bewerbung</h1>
</div>

<?php if ($submitted): ?>
  <div class="careers-card-form">
    <h2 style="margin-top:0;">Danke für deine Bewerbung!</h2>
    <p style="color:var(--c-text-muted);">Wir haben deine Bewerbung erhalten und melden uns, sobald wir sie gesichtet haben.</p>
  </div>
<?php else: ?>
  <div class="careers-card-form">
    <?php if ($errors): ?>
      <div class="careers-flash careers-flash-error"><?php foreach ($errors as $err): ?><?= e($err) ?><br><?php endforeach; ?></div>
    <?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="job" value="<?= e($slug) ?>">
      <div class="careers-form-row">
        <div class="careers-field">
          <label>Name *</label>
          <input type="text" name="applicant_name" value="<?= e($_POST['applicant_name'] ?? '') ?>" required>
        </div>
        <div class="careers-field">
          <label>Alter</label>
          <input type="text" name="applicant_age" value="<?= e($_POST['applicant_age'] ?? '') ?>">
        </div>
      </div>
      <div class="careers-field">
        <label>Discord-Tag (optional)</label>
        <input type="text" name="discord_tag" value="<?= e($_POST['discord_tag'] ?? '') ?>" placeholder="z. B. deinname — falls du Discord nutzt">
      </div>
      <div class="careers-field">
        <label>Verfügbarkeit (Zeiten/Tage)</label>
        <textarea name="availability" rows="3"><?= e($_POST['availability'] ?? '') ?></textarea>
      </div>
      <div class="careers-field">
        <label>Motivation *</label>
        <textarea name="motivation" rows="5" required><?= e($_POST['motivation'] ?? '') ?></textarea>
      </div>
      <?php foreach ($questions as $q): ?>
      <div class="careers-field">
        <label><?= e($q['label']) ?></label>
        <textarea name="question_<?= $q['id'] ?>" rows="3"><?= e($_POST['question_' . $q['id']] ?? '') ?></textarea>
      </div>
      <?php endforeach; ?>
      <button class="careers-btn-primary" type="submit">🖊 Bewerbung absenden</button>
    </form>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/careers_footer.php'; ?>
