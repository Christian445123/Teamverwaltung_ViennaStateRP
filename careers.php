<?php
require __DIR__ . '/bootstrap.php';
// Bewusst kein Login nötig — öffentliche Bewerbungsseite.
$db = DB::get();

$postings = $db->query("SELECT * FROM job_postings WHERE status = 'open' ORDER BY created_at DESC")->fetchAll();

function careers_excerpt(?string $text, int $length = 140): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text ?? ''));
    if ($text === '') return '';
    return mb_strlen($text) > $length ? mb_substr($text, 0, $length) . '…' : $text;
}

$pageTitle = 'Offene Stellen';
require __DIR__ . '/includes/careers_header.php';
?>
<div class="careers-hero">
  <h1>ViennaStateRP</h1>
</div>
<hr class="careers-hero-divider">

<?php if (!$postings): ?>
  <div class="careers-empty">Aktuell gibt es keine offenen Stellen. Schau später wieder vorbei!</div>
<?php else: ?>
<div class="careers-grid">
  <?php foreach ($postings as $p): ?>
    <a href="<?= url('careers_job.php?job=' . urlencode($p['slug'])) ?>" class="careers-card">
      <?php if ($p['image_url']): ?>
        <img class="careers-card-img" src="<?= e($p['image_url']) ?>" alt="">
      <?php endif; ?>
      <div class="careers-card-body">
        <h3><?= e($p['title']) ?></h3>
        <?php if ($excerpt = careers_excerpt($p['description'])): ?>
          <div class="careers-card-excerpt"><?= e($excerpt) ?></div>
        <?php endif; ?>
      </div>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/careers_footer.php'; ?>
