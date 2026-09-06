<?php
require __DIR__ . '/bootstrap.php';
// Bewusst kein Login nötig — öffentliche Bewerbungsseite.
$db = DB::get();

$postings = $db->query("SELECT * FROM job_postings WHERE status = 'open' ORDER BY created_at DESC")->fetchAll();

$pageTitle = 'Offene Stellen';
require __DIR__ . '/includes/header.php';
?>
<div class="public-page">
  <div class="page-header">
    <h1>Offene Stellen bei ViennaStateRP</h1>
  </div>

  <?php if (!$postings): ?>
    <div class="card"><div class="empty-state">Aktuell gibt es keine offenen Stellen. Schau später wieder vorbei!</div></div>
  <?php else: foreach ($postings as $p): ?>
    <div class="card">
      <div class="card-title" style="font-size:18px;"><?= e($p['title']) ?></div>
      <?php if ($p['description']): ?><p style="white-space:pre-wrap;"><?= e($p['description']) ?></p><?php endif; ?>
      <a href="<?= url('careers_apply.php?job=' . urlencode($p['slug'])) ?>" class="btn">Jetzt bewerben</a>
    </div>
  <?php endforeach; endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
