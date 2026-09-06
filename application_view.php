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

$id = (int) ($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT a.*, jp.title AS posting_title, jp.team_id FROM applications a JOIN job_postings jp ON jp.id = a.posting_id WHERE a.id = ?");
$stmt->execute([$id]);
$application = $stmt->fetch();
if (!$application) {
    flash('error', 'Bewerbung nicht gefunden.');
    redirect(url('applications.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_notes') {
        $db->prepare("UPDATE applications SET notes = ? WHERE id = ?")->execute([trim($_POST['notes'] ?? '') ?: null, $id]);
        flash('success', 'Notiz gespeichert.');
        redirect(url('application_view.php?id=' . $id));
    }

    if ($action === 'invite' && $application['status'] === 'pending') {
        $db->prepare("UPDATE applications SET status = 'interview_invited' WHERE id = ?")->execute([$id]);
        audit_log('application.invite', "Bewerbung #{$id} zum Gespräch eingeladen");
        flash('success', 'Zur Terminbuchung eingeladen. Buchungslink unten an die Person weitergeben.');
        redirect(url('application_view.php?id=' . $id));
    }

    if ($action === 'reject' && in_array($application['status'], ['pending', 'interview_invited', 'interview_scheduled'], true)) {
        $db->prepare("UPDATE applications SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
            ->execute([$user['id'], $id]);
        audit_log('application.reject', "Bewerbung #{$id} abgelehnt");
        flash('success', 'Bewerbung abgelehnt.');
        redirect(url('application_view.php?id=' . $id));
    }

    if ($action === 'release_slot' && $application['status'] === 'interview_scheduled') {
        $db->prepare("UPDATE interview_slots SET application_id = NULL WHERE application_id = ?")->execute([$id]);
        $db->prepare("UPDATE applications SET status = 'interview_invited' WHERE id = ?")->execute([$id]);
        flash('success', 'Termin freigegeben — die Person kann sich einen neuen aussuchen.');
        redirect(url('application_view.php?id=' . $id));
    }

    if ($action === 'accept' && in_array($application['status'], ['pending', 'interview_invited', 'interview_scheduled'], true)) {
        $discordMatch = null;
        if ($application['discord_tag'] && Settings::isBotConfigured()) {
            $discordMatch = DiscordClient::findGuildMemberByTag($application['discord_tag']);
            if ($discordMatch) {
                // Schon einem bestehenden Mitglied zugeordnet (z. B. Wiederbewerbung) — nicht
                // verknüpfen, sonst schlägt der INSERT an der UNIQUE-Constraint auf discord_id fehl.
                $existsStmt = $db->prepare("SELECT id FROM users WHERE discord_id = ?");
                $existsStmt->execute([$discordMatch['id']]);
                if ($existsStmt->fetch()) {
                    $discordMatch = null;
                }
            }
        }
        $lowestRank = $db->query("SELECT id FROM ranks ORDER BY level ASC LIMIT 1")->fetchColumn();
        $stmt = $db->prepare("INSERT INTO users (discord_id, discord_username, discord_avatar, display_name, rank_id, status)
            VALUES (?, ?, ?, ?, ?, 'active')");
        $stmt->execute([
            $discordMatch['id'] ?? null,
            $discordMatch['username'] ?? $application['discord_tag'],
            $discordMatch['avatar'] ?? null,
            $application['applicant_name'],
            $lowestRank ?: null,
        ]);
        $newUserId = (int) $db->lastInsertId();

        if ($application['team_id']) {
            $db->prepare("INSERT IGNORE INTO user_teams (user_id, team_id) VALUES (?, ?)")->execute([$newUserId, $application['team_id']]);
        }

        $db->prepare("UPDATE applications SET status = 'accepted', created_user_id = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?")
            ->execute([$newUserId, $user['id'], $id]);
        audit_log('application.accept', "Bewerbung #{$id} angenommen, Mitglied #{$newUserId} angelegt");

        $msg = 'Bewerbung angenommen, Mitgliedskonto wurde erstellt.';
        $msg .= $discordMatch ? ' Discord-Account wurde automatisch erkannt und verknüpft.' : ' Discord-Account konnte nicht eindeutig zugeordnet werden — bitte im Mitglied-Profil manuell prüfen.';
        flash('success', $msg);
        redirect(url('member_form.php?id=' . $newUserId));
    }

    redirect(url('application_view.php?id=' . $id));
}

$answers = $db->prepare("
  SELECT q.label, aa.answer FROM job_posting_questions q
  LEFT JOIN application_answers aa ON aa.question_id = q.id AND aa.application_id = ?
  WHERE q.posting_id = ? ORDER BY q.sort_order ASC, q.id ASC
");
$answers->execute([$id, $application['posting_id']]);
$answers = $answers->fetchAll();

$bookedSlot = null;
if ($application['status'] === 'interview_scheduled') {
    $stmt = $db->prepare("SELECT * FROM interview_slots WHERE application_id = ?");
    $stmt->execute([$id]);
    $bookedSlot = $stmt->fetch();
}

$bookingUrl = Settings::appUrl() . '/careers_booking.php?token=' . urlencode($application['booking_token']);

$pageTitle = 'Bewerbung: ' . $application['applicant_name'];
$active = 'applications';
require __DIR__ . '/includes/header.php';
?>
<div class="page-header">
  <h1><?= e($application['applicant_name']) ?> <span class="badge status-<?= e($application['status']) ?>"><?= e($statusLabels[$application['status']] ?? $application['status']) ?></span></h1>
  <a href="<?= url('applications.php') ?>" class="btn secondary">Zurück</a>
</div>

<div class="grid grid-2">
  <div class="card">
    <h2>Angaben</h2>
    <p><strong>Stelle:</strong> <?= e($application['posting_title']) ?></p>
    <?php if ($application['applicant_age']): ?><p><strong>Alter:</strong> <?= (int) $application['applicant_age'] ?></p><?php endif; ?>
    <?php if ($application['discord_tag']): ?><p><strong>Discord-Tag:</strong> <?= e($application['discord_tag']) ?></p><?php endif; ?>
    <?php if ($application['availability']): ?><p><strong>Verfügbarkeit:</strong><br><span style="white-space:pre-wrap;"><?= e($application['availability']) ?></span></p><?php endif; ?>
    <?php if ($application['motivation']): ?><p><strong>Motivation:</strong><br><span style="white-space:pre-wrap;"><?= e($application['motivation']) ?></span></p><?php endif; ?>
    <?php foreach ($answers as $a): ?>
      <p><strong><?= e($a['label']) ?>:</strong><br><span style="white-space:pre-wrap;"><?= e($a['answer'] ?: '–') ?></span></p>
    <?php endforeach; ?>
    <p class="text-muted" style="font-size:12px;">Beworben am <?= e(fmt_datetime($application['created_at'])) ?> Uhr</p>
  </div>

  <div class="card">
    <h2>Status &amp; Aktionen</h2>

    <?php if (in_array($application['status'], ['interview_invited', 'interview_scheduled'], true)): ?>
      <div class="field">
        <label>Buchungslink für die Bewerbungsperson</label>
        <div class="copy-box">
          <code><?= e($bookingUrl) ?></code>
          <button type="button" class="btn small secondary" data-copy="<?= e($bookingUrl) ?>">Kopieren</button>
        </div>
        <div class="field-hint">Manuell per Discord-DM oder E-Mail an die Person schicken — es gibt keinen automatischen Versand.</div>
      </div>
    <?php endif; ?>

    <?php if ($bookedSlot): ?>
      <p><strong>Gebuchter Termin:</strong> <?= e(fmt_datetime($bookedSlot['start_time'])) ?> Uhr</p>
      <form method="post" style="margin-bottom:16px;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="release_slot">
        <button class="btn small secondary" type="submit">Termin freigeben</button>
      </form>
    <?php endif; ?>

    <?php if ($application['status'] === 'accepted'): ?>
      <p class="text-muted">Angenommen am <?= e(fmt_datetime($application['reviewed_at'])) ?> Uhr.
        <?php if ($application['created_user_id']): ?><a href="<?= url('member_form.php?id=' . $application['created_user_id']) ?>">Zum Mitgliedsprofil</a><?php endif; ?>
      </p>
    <?php elseif ($application['status'] === 'rejected'): ?>
      <p class="text-muted">Abgelehnt am <?= e(fmt_datetime($application['reviewed_at'])) ?> Uhr.</p>
    <?php else: ?>
      <div class="btn-row" style="margin-top:8px;">
        <?php if ($application['status'] === 'pending'): ?>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="invite">
            <button class="btn" type="submit">Zum Gespräch einladen</button>
          </form>
        <?php endif; ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="accept">
          <button class="btn secondary" type="submit" data-confirm="Bewerbung annehmen und Mitgliedskonto anlegen?">Annehmen</button>
        </form>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reject">
          <button class="btn danger" type="submit" data-confirm="Bewerbung wirklich ablehnen?">Ablehnen</button>
        </form>
      </div>
    <?php endif; ?>

    <div class="field" style="margin-top:20px;">
      <label>Interne Notiz</label>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_notes">
        <textarea name="notes" rows="4"><?= e($application['notes'] ?? '') ?></textarea>
        <button class="btn small secondary" type="submit" style="margin-top:8px;">Notiz speichern</button>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
