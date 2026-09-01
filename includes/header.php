<?php
defined('APP_BOOTSTRAPPED') || exit('Direct access not permitted.');
/** @var array|null $user */
$user = current_user();
$pageTitle = $pageTitle ?? 'Teamverwaltung';
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> · Teamverwaltung</title>
<link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
</head>
<body>
<?php if ($user): ?>
<div class="app-shell">
  <aside class="sidebar">
    <div class="brand">
      <span class="brand-dot"></span> Teamverwaltung
    </div>
    <nav class="nav">
      <a href="<?= url('index.php') ?>" class="<?= ($active ?? '') === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
      <a href="<?= url('meetings.php') ?>" class="<?= ($active ?? '') === 'meetings' ? 'active' : '' ?>">Besprechungen</a>
      <a href="<?= url('members.php') ?>" class="<?= ($active ?? '') === 'members' ? 'active' : '' ?>">Mitglieder</a>
      <a href="<?= url('teams.php') ?>" class="<?= ($active ?? '') === 'teams' ? 'active' : '' ?>">Teams</a>
      <?php if (Perm::has($user, 'ranks.manage')): ?>
      <a href="<?= url('ranks.php') ?>" class="<?= ($active ?? '') === 'ranks' ? 'active' : '' ?>">Ränge</a>
      <?php endif; ?>
      <?php if (Perm::has($user, 'discord.manage')): ?>
      <a href="<?= url('settings.php') ?>" class="<?= ($active ?? '') === 'settings' ? 'active' : '' ?>">Discord &amp; Einstellungen</a>
      <?php endif; ?>
    </nav>
    <div class="sidebar-footer">
      <a href="<?= url('profile.php') ?>" class="user-chip <?= ($active ?? '') === 'profile' ? 'active' : '' ?>">
        <?php if (!empty($user['discord_avatar']) || !empty($user['discord_id'])): ?>
          <img class="avatar" src="<?= e(DiscordClient::avatarUrl($user['discord_id'], $user['discord_avatar'])) ?>" alt="">
        <?php else: ?>
          <span class="avatar avatar-fallback"><?= e(mb_substr($user['display_name'], 0, 1)) ?></span>
        <?php endif; ?>
        <span><?= e($user['display_name']) ?></span>
      </a>
      <a href="<?= url('logout.php') ?>" class="logout-link">Abmelden</a>
    </div>
  </aside>
  <main class="content">
    <?php foreach (get_flashes() as $flash): ?>
      <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>
<?php else: ?>
<main class="content-plain">
    <?php foreach (get_flashes() as $flash): ?>
      <div class="flash flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
    <?php endforeach; ?>
<?php endif; ?>
