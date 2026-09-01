<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Europe/Vienna');

require_once __DIR__ . '/src/Env.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Settings.php';
require_once __DIR__ . '/src/helpers.php';
require_once __DIR__ . '/src/Auth.php';
require_once __DIR__ . '/src/Perm.php';
require_once __DIR__ . '/src/DiscordClient.php';

// Warm up the DB connection (also runs migrations on first request).
DB::get();

function current_user(): ?array
{
    return Auth::currentUser();
}
