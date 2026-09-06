<?php
require __DIR__ . '/bootstrap.php';
$user = Auth::requirePermission('applications.manage');
$db = DB::get();

$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$posting = null;
$questions = [];
$slots = [];
if ($id) {
    $stmt = $db->prepare("SELECT * FROM job_postings WHERE id = ?");
    $stmt->execute([$id]);
    $posting = $stmt->fetch();
    if (!$posting) {
        flash('error', 'Ausschreibung nicht gefunden.');
        redirect(url('job_postings.php'));
    }
    $stmt = $db->prepare("SELECT * FROM job_posting_questions WHERE posting_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$id]);
    $questions = $stmt->fetchAll();
    $stmt = $db->prepare("
        SELECT s.*, a.applicant_name FROM interview_slots s
        LEFT JOIN applications a ON a.id = s.application_id
        WHERE s.posting_id = ? ORDER BY s.start_time ASC
    ");
    $stmt->execute([$id]);
    $slots = $stmt->fetchAll();
}

function slugify_title(string $title): string
{
    $slug = strtolower(trim($title));
    $slug = strtr($slug, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    return trim($slug, '-') ?: 'stelle';
}

function unique_slug(PDO $db, string $title, ?int $excludeId): string
{
    $base = slugify_title($title);
    $slug = $base;
    $i = 2;
    while (true) {
        $stmt = $db->prepare("SELECT id FROM job_postings WHERE slug = ? AND id != ?");
        $stmt->execute([$slug, $excludeId ?: 0]);
        if (!$stmt->fetch()) return $slug;
        $slug = $base . '-' . $i;
        $i++;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? 'save';

    if ($action === 'add_slot' && $posting) {
        $startRaw = $_POST['slot_start'] ?? '';
        $endRaw = $_POST['slot_end'] ?? '';
        if (strtotime($startRaw)) {
            $start = date('Y-m-d H:i:s', strtotime($startRaw));
            $end = $endRaw && strtotime($endRaw) ? date('Y-m-d H:i:s', strtotime($endRaw)) : null;
            $db->prepare("INSERT INTO interview_slots (posting_id, start_time, end_time, created_by) VALUES (?, ?, ?, ?)")
                ->execute([$posting['id'], $start, $end, $user['id']]);
            flash('success', 'Termin-Slot hinzugefügt.');
        } else {
            flash('error', 'Ungültige Startzeit.');
        }
        redirect(url('job_posting_form.php?id=' . $posting['id']));
    }

    if ($action === 'delete_slot' && $posting) {
        $slotId = (int) ($_POST['slot_id'] ?? 0);
        $db->prepare("DELETE FROM interview_slots WHERE id = ? AND posting_id = ? AND application_id IS NULL")
            ->execute([$slotId, $posting['id']]);
        flash('success', 'Termin-Slot entfernt.');
        redirect(url('job_posting_form.php?id=' . $posting['id']));
    }

    // action === 'save'
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $requirements = trim($_POST['requirements'] ?? '');
    $imageUrl = trim($_POST['image_url'] ?? '');
    $teamId = $_POST['team_id'] !== '' ? (int) $_POST['team_id'] : null;
    $status = $_POST['status'] === 'closed' ? 'closed' : 'open';
    $questionLines = array_values(array_filter(array_map('trim', explode("\n", $_POST['questions'] ?? ''))));

    if ($title === '') {
        flash('error', 'Titel ist erforderlich.');
        redirect(url('job_posting_form.php') . ($id ? '?id=' . $id : ''));
    }

    if ($posting) {
        $db->prepare("UPDATE job_postings SET title=?, description=?, requirements=?, image_url=?, team_id=?, status=? WHERE id=?")
            ->execute([$title, $description ?: null, $requirements ?: null, $imageUrl ?: null, $teamId, $status, $posting['id']]);
        $postingId = $posting['id'];
        audit_log('job_posting.update', $title);
    } else {
        $slug = unique_slug($db, $title, null);
        $db->prepare("INSERT INTO job_postings (title, slug, description, requirements, image_url, team_id, status, created_by) VALUES (?, ?, ?, ?, ?, ?, 'open', ?)")
            ->execute([$title, $slug, $description ?: null, $requirements ?: null, $imageUrl ?: null, $teamId, $user['id']]);
        $postingId = $db->lastInsertId();
        audit_log('job_posting.create', $title);
    }

    // Zusatzfragen per Label abgleichen: bestehende (Text unverändert) behalten ihre ID (damit
    // schon eingegangene Antworten zugeordnet bleiben), neue Zeilen werden angelegt, entfernte
    // Zeilen samt ihrer Antworten gelöscht (ON DELETE CASCADE).
    $existingQuestions = $db->prepare("SELECT id, label FROM job_posting_questions WHERE posting_id = ?");
    $existingQuestions->execute([$postingId]);
    $existingByLabel = [];
    foreach ($existingQuestions->fetchAll() as $q) {
        $existingByLabel[$q['label']] = $q['id'];
    }
    $keptLabels = [];
    $insertQuestion = $db->prepare("INSERT INTO job_posting_questions (posting_id, label, sort_order) VALUES (?, ?, ?)");
    $updateOrder = $db->prepare("UPDATE job_posting_questions SET sort_order = ? WHERE id = ?");
    foreach ($questionLines as $i => $label) {
        if (isset($existingByLabel[$label])) {
            $updateOrder->execute([$i, $existingByLabel[$label]]);
        } else {
            $insertQuestion->execute([$postingId, $label, $i]);
        }
        $keptLabels[] = $label;
    }
    $toRemove = array_diff(array_keys($existingByLabel), $keptLabels);
    if ($toRemove) {
        $placeholders = implode(',', array_fill(0, count($toRemove), '?'));
        $ids = array_map(fn($l) => $existingByLabel[$l], $toRemove);
        $db->prepare("DELETE FROM job_posting_questions WHERE id IN ({$placeholders})")->execute($ids);
    }

    flash('success', $posting ? 'Ausschreibung aktualisiert.' : 'Ausschreibung erstellt.');
    redirect(url('job_posting_form.php?id=' . $postingId));
}

$teams = $db->query("SELECT * FROM teams ORDER BY name ASC")->fetchAll();

$pageTitle = $posting ? 'Ausschreibung bearbeiten' : 'Ausschreibung erstellen';
$active = 'job_postings';
require __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <h1><?= e($pageTitle) ?></h1>
  <?php if ($posting && $posting['status'] === 'open'): ?>
    <a href="<?= url('careers_job.php?job=' . urlencode($posting['slug'])) ?>" class="btn secondary" target="_blank">Öffentliche Ansicht</a>
  <?php endif; ?>
</div>

<div class="card" style="max-width:640px;">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <div class="field">
      <label>Titel *</label>
      <input type="text" name="title" value="<?= e($posting['title'] ?? '') ?>" required>
    </div>
    <div class="field">
      <label>Bild-URL (optional)</label>
      <input type="text" name="image_url" value="<?= e($posting['image_url'] ?? '') ?>" placeholder="https://…">
      <div class="field-hint">Banner-Bild auf Karte und Detailseite. Muss extern gehostet sein (kein Upload).</div>
    </div>
    <div class="field">
      <label>Beschreibung</label>
      <textarea name="description" rows="5"><?= e($posting['description'] ?? '') ?></textarea>
      <div class="field-hint">Allgemeiner Überblick über die Stelle. Leerzeile = neuer Absatz; "Begriff — Erklärung" am Zeilenanfang wird auf der Bewerbungsseite fett hervorgehoben.</div>
    </div>
    <div class="field">
      <label>Anforderungen (optional)</label>
      <textarea name="requirements" rows="6"><?= e($posting['requirements'] ?? '') ?></textarea>
      <div class="field-hint">Wird auf der Bewerbungsseite unter „Dein Profil" angezeigt. Gleiche Formatierung wie bei der Beschreibung.</div>
    </div>
    <div class="form-row">
      <div class="field">
        <label>Team (optional)</label>
        <select name="team_id">
          <option value="">– keins –</option>
          <?php foreach ($teams as $t): ?>
            <option value="<?= $t['id'] ?>" <?= ($posting['team_id'] ?? null) == $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="field-hint">Wird bei Annahme automatisch als Team des neuen Mitglieds gesetzt.</div>
      </div>
      <?php if ($posting): ?>
      <div class="field">
        <label>Status</label>
        <select name="status">
          <option value="open" <?= $posting['status'] === 'open' ? 'selected' : '' ?>>Offen</option>
          <option value="closed" <?= $posting['status'] === 'closed' ? 'selected' : '' ?>>Geschlossen</option>
        </select>
      </div>
      <?php else: ?>
      <input type="hidden" name="status" value="open">
      <?php endif; ?>
    </div>
    <div class="field">
      <label>Zusätzliche Fragen (eine pro Zeile, optional)</label>
      <textarea name="questions" rows="4"><?= e(implode("\n", array_column($questions, 'label'))) ?></textarea>
      <div class="field-hint">Werden Bewerbern zusätzlich zu Name, Alter, Discord-Tag, Motivation und Verfügbarkeit gestellt.</div>
    </div>
    <button class="btn" type="submit">Speichern</button>
  </form>
</div>

<?php if ($posting): ?>
<div class="card" style="max-width:640px;">
  <h2>Bewerbungsgespräch-Termine</h2>
  <p class="text-muted" style="margin-top:0;">Freie Slots, aus denen sich eingeladene Bewerber:innen per Buchungslink selbst einen Termin aussuchen.</p>
  <?php if (!$slots): ?>
    <div class="empty-state">Noch keine Termin-Slots angelegt.</div>
  <?php else: ?>
  <div class="slot-list" style="margin-bottom:16px;">
    <?php foreach ($slots as $s): ?>
      <div class="slot-item">
        <div>
          <strong><?= e(fmt_datetime($s['start_time'])) ?> Uhr</strong>
          <?php if ($s['end_time']): ?><span class="text-muted"> – <?= e(fmt_datetime($s['end_time'])) ?> Uhr</span><?php endif; ?>
        </div>
        <?php if ($s['application_id']): ?>
          <span class="badge status-accepted">Gebucht: <?= e($s['applicant_name']) ?></span>
        <?php else: ?>
          <form method="post" data-confirm="Slot wirklich entfernen?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_slot">
            <input type="hidden" name="slot_id" value="<?= $s['id'] ?>">
            <button class="btn small danger" type="submit">Entfernen</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <form method="post" class="form-row">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_slot">
    <div class="field">
      <label>Beginn *</label>
      <input type="datetime-local" name="slot_start" required>
    </div>
    <div class="field">
      <label>Ende (optional)</label>
      <input type="datetime-local" name="slot_end">
    </div>
    <div class="field" style="align-self:flex-end;">
      <button class="btn secondary" type="submit">Slot hinzufügen</button>
    </div>
  </form>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
