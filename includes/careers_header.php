<?php
defined('APP_BOOTSTRAPPED') || exit('Direct access not permitted.');
$pageTitle = $pageTitle ?? 'Karriere';
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> · ViennaStateRP</title>
<link rel="stylesheet" href="<?= url('assets/css/careers.css') ?>">
</head>
<body class="careers-body">
<div class="careers-topbar">
  <a href="<?= url('careers.php') ?>" class="careers-logo"><span class="dot"></span> ViennaStateRP Team</a>
  <a href="<?= url('login.php') ?>" class="careers-btn-outline">🔑 Team-Login</a>
</div>
<div class="careers-container">
<?php foreach (get_flashes() as $flash): ?>
  <div class="careers-flash careers-flash-<?= e($flash['type']) ?>"><?= e($flash['message']) ?></div>
<?php endforeach; ?>
