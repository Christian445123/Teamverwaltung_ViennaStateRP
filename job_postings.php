<?php
require __DIR__ . '/bootstrap.php';
$user = Auth::requirePermission('applications.manage');
$db = DB::get();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $id = (int) ($_POST['id'] ?? 0);

    if ($action === 'toggle_status' && $id) {
        $stmt = $db->prepare("SELECT title, status FROM job_postings WHERE id = ?");
        $stmt->execute([$id]);
        $posting = $stmt->fetch();
        if ($posting) {
            $new = $posting['status'] === 'open' ? 'closed' : 'open';
            $db->prepare("UPDATE job_postings SET status = ? WHERE id = ?")->execute([$new, $id]);
            audit_log('job_posting.' . $new, $posting['title']);
            flash('success', $new === 'open' ? 'Ausschreibung wieder geöffnet.' : 'Ausschreibung geschlossen.');
        }
    } elseif ($action === 'delete' && $id) {
        $stmt = $db->prepare("SELECT COUNT(*) c FROM applications WHERE posting_id = ?");
        $stmt->execute([$id]);
        if ((int) $stmt->fetch()['c'] > 0) {
            flash('error', 'Ausschreibung hat bereits Bewerbungen und kann nicht gelöscht werden — stattdessen schließen.');
        } else {
            $stmt = $db->prepare("SELECT title FROM job_postings WHERE id = ?");
            $stmt->execute([$id]);
            $title = $stmt->fetchColumn() ?: "#{$id}";
            $db->prepare("DELETE FROM job_postings WHERE id = ?")->execute([$id]);
            audit_log('job_posting.delete', $title);
            flash('success', 'Ausschreibung gelöscht.');
        }
    }
    redirect(url('job_postings.php'));
}

$postings = $db->query("
  SELECT jp.*, t.name AS team_name,
    (SELECT COUNT(*) FROM applications a WHERE a.posting_id = jp.id) AS application_count,
    (SELECT COUNT(*) FROM applications a WHERE a.posting_id = jp.id AND a.status = 'pending') AS pending_count
  FROM job_postings jp LEFT JOIN teams t ON t.id = jp.team_id
  ORDER BY (jp.status = 'open') DESC, jp.created_at DESC
")->fetchAll();

$pageTitle = 'Stellenausschreibungen';
$active = 'job_postings';
require __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <h1>Stellenausschreibungen</h1>
  <a href="<?= url('job_posting_form.php') ?>" class="btn">+ Ausschreibung erstellen</a>
</div>

<div class="card">
  <p class="text-muted" style="margin-top:0;">Öffentliche Bewerbungsseite: <a href="<?= url('careers.php') ?>" target="_blank" style="color:var(--accent);"><?= e(Settings::appUrl() . '/careers.php') ?></a></p>
  <?php if (!$postings): ?>
    <div class="empty-state">Noch keine Ausschreibungen erstellt.</div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Titel</th><th>Status</th><th>Team</th><th>Bewerbungen</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($postings as $p): ?>
        <tr>
          <td><a href="<?= url('job_posting_form.php?id=' . $p['id']) ?>"><strong><?= e($p['title']) ?></strong></a></td>
          <td><span class="badge status-<?= e($p['status']) ?>"><?= $p['status'] === 'open' ? 'Offen' : 'Geschlossen' ?></span></td>
          <td><?= $p['team_name'] ? e($p['team_name']) : '–' ?></td>
          <td><?= (int) $p['application_count'] ?><?= $p['pending_count'] > 0 ? ' (' . (int) $p['pending_count'] . ' offen)' : '' ?></td>
          <td>
            <div class="btn-row">
              <a href="<?= url('applications.php?posting=' . $p['id']) ?>" class="btn small secondary">Bewerbungen</a>
              <form method="post" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                <button class="btn small secondary" type="submit"><?= $p['status'] === 'open' ? 'Schließen' : 'Öffnen' ?></button>
              </form>
              <form method="post" style="display:inline;" data-confirm="Ausschreibung wirklich löschen?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $p['id'] ?>">
                <button class="btn small danger" type="submit">Löschen</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
