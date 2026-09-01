<?php
require __DIR__ . '/../bootstrap.php';

$url = DiscordClient::getAuthorizeUrl();
if (!$url) {
    flash('error', 'Discord ist noch nicht konfiguriert.');
    redirect(url('login.php'));
}

$state = bin2hex(random_bytes(16));
$_SESSION['discord_oauth_state'] = $state;
$_SESSION['discord_link_mode'] = current_user() ? true : false;

redirect($url . '&state=' . urlencode($state));
