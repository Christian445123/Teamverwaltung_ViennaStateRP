<?php
require __DIR__ . '/bootstrap.php';
$user = Auth::requirePermission('applications.manage');
$db = DB::get();

$statusLabels = [
    'pending' => 'Neu',
    'interview_invited' => 'Zum Gespräch eingeladen',
    'interview_scheduled' => 'Gespräch terminiert',
    'accepted' => 'Angenommen',
    'rejected' => 'Abgelehnt',
];

$postingFilter = isset($_GET['posting']) ? (int) $_GET['posting'] : 0;
$statusFilter = $_GET['status'] ?? '';

$where = [];
$params = [];
if ($postingFilter) {
    $where[] = 'a.posting_id = ?';
    $params[] = $postingFilter;
}
if ($statusFilter && isset($statusLabels[$statusFilter])) {
    $where[] = 'a.status = ?';
    $params[] = $statusFilter;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $db->prepare("
  SELECT a.*, jp.title AS posting_title
  FROM applications a JOIN job_postings jp ON jp.id = a.posting_id
  {$whereSql}
  ORDER BY (a.status = 'pending') DESC, a.created_at DESC
");
$stmt->execute($params);
$applications = $stmt->fetchAll();

$postings = $db->query("SELECT id, title FROM job_postings ORDER BY title ASC")->fetchAll();

$pageTitle = 'Bewerbungen';
$active = 'applications';
require __DIR__ . '/includes/header.php';
?>
<div class="page-header"><h1>Bewerbungen</h1></div>

<div class="card">
  <form method="get" class="form-row" style="margin-bottom:0;">
    <div class="field">
      <label>Stelle</label>
      <select name="posting" onchange="this.form.submit()">
        <option value="">Alle Stellen</option>
        <?php foreach ($postings as $p): ?>
          <option value="<?= $p['id'] ?>" <?= $postingFilter === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['title']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Status</label>
      <select name="status" onchange="this.form.submit()">
        <option value="">Alle Status</option>
        <?php foreach ($statusLabels as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>
</div>

<div class="card">
  <?php if (!$applications): ?>
    <div class="empty-state">Keine Bewerbungen gefunden.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Name</th><th>Stelle</th><th>Status</th><th>Beworben am</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($applications as $a): ?>
        <tr>
          <td><?= e($a['applicant_name']) ?></td>
          <td><?= e($a['posting_title']) ?></td>
          <td><span class="badge status-<?= e($a['status']) ?>"><?= e($statusLabels[$a['status']] ?? $a['status']) ?></span></td>
          <td><?= e(fmt_datetime($a['created_at'])) ?></td>
          <td><a href="<?= url('application_view.php?id=' . $a['id']) ?>" class="btn small secondary">Ansehen</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
