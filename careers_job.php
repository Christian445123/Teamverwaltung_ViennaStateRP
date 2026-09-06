<?php
require __DIR__ . '/bootstrap.php';
// Bewusst kein Login nötig — öffentliche Bewerbungsseite.
$db = DB::get();

$slug = $_GET['job'] ?? '';
$stmt = $db->prepare("SELECT * FROM job_postings WHERE slug = ?");
$stmt->execute([$slug]);
$posting = $stmt->fetch();

if (!$posting || $posting['status'] !== 'open') {
    flash('error', 'Diese Ausschreibung ist nicht mehr verfügbar.');
    redirect(url('careers.php'));
}

$pageTitle = $posting['title'];
require __DIR__ . '/includes/careers_header.php';
?>
<a href="<?= url('careers.php') ?>" class="careers-breadcrumb">Ausschreibungen</a>
<div class="careers-detail-header">
  <h1><?= e($posting['title']) ?></h1>
  <a href="<?= url('careers.php') ?>" class="careers-back">←</a>
</div>

<?php if ($posting['image_url']): ?>
  <img src="<?= e($posting['image_url']) ?>" alt="" style="width:100%;border-radius:14px;margin-bottom:24px;display:block;">
<?php endif; ?>

<div class="careers-richtext"><?= render_rich_text($posting['description']) ?></div>

<?php if ($posting['requirements']): ?>
<hr class="careers-divider">
<h2>Dein Profil</h2>
<div class="careers-richtext"><?= render_rich_text($posting['requirements']) ?></div>
<?php endif; ?>

<hr class="careers-divider">
<div class="careers-callout">
  <div class="careers-callout-text">
    <span class="careers-callout-icon">👋</span><strong>Jetzt du!</strong>
    <span>Du hast bis hier gelesen? Dann versuche es doch einfach mal mit einer Bewerbung. Wir freuen uns auf dich!</span>
  </div>
  <a href="<?= url('careers_apply.php?job=' . urlencode($posting['slug'])) ?>" class="careers-btn-primary">🖊 Bewerben</a>
</div>

<?php require __DIR__ . '/includes/careers_footer.php'; ?>
