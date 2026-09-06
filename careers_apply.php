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

$submitted = false;
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

        redirect(url('careers_apply.php?job=' . urlencode($slug) . '&submitted=1'));
    }
}

$submitted = isset($_GET['submitted']);

$pageTitle = 'Bewerbung: ' . $posting['title'];
require __DIR__ . '/includes/header.php';
?>
<div class="public-page">
  <div class="page-header">
    <h1>Bewerbung: <?= e($posting['title']) ?></h1>
    <a href="<?= url('careers.php') ?>" class="btn secondary">Alle Stellen</a>
  </div>

  <?php if ($submitted): ?>
    <div class="card">
      <h2>Danke für deine Bewerbung!</h2>
      <p>Wir haben deine Bewerbung erhalten und melden uns, sobald wir sie gesichtet haben.</p>
    </div>
  <?php else: ?>
    <div class="card">
      <?php if ($errors): ?>
        <div class="flash flash-error"><?php foreach ($errors as $err): ?><?= e($err) ?><br><?php endforeach; ?></div>
      <?php endif; ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="job" value="<?= e($slug) ?>">
        <div class="form-row">
          <div class="field">
            <label>Name *</label>
            <input type="text" name="applicant_name" value="<?= e($_POST['applicant_name'] ?? '') ?>" required>
          </div>
          <div class="field">
            <label>Alter</label>
            <input type="text" name="applicant_age" value="<?= e($_POST['applicant_age'] ?? '') ?>">
          </div>
        </div>
        <div class="field">
          <label>Discord-Tag (optional)</label>
          <input type="text" name="discord_tag" value="<?= e($_POST['discord_tag'] ?? '') ?>" placeholder="z. B. deinname — falls du Discord nutzt">
        </div>
        <div class="field">
          <label>Verfügbarkeit (Zeiten/Tage)</label>
          <textarea name="availability" rows="3"><?= e($_POST['availability'] ?? '') ?></textarea>
        </div>
        <div class="field">
          <label>Motivation *</label>
          <textarea name="motivation" rows="5" required><?= e($_POST['motivation'] ?? '') ?></textarea>
        </div>
        <?php foreach ($questions as $q): ?>
        <div class="field">
          <label><?= e($q['label']) ?></label>
          <textarea name="question_<?= $q['id'] ?>" rows="3"><?= e($_POST['question_' . $q['id']] ?? '') ?></textarea>
        </div>
        <?php endforeach; ?>
        <button class="btn" type="submit">Bewerbung absenden</button>
      </form>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
