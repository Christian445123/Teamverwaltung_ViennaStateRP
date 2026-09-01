<?php

/**
 * Markiert einen ordnungsgemäß über bootstrap.php gestarteten Request.
 * src/*.php und includes/*.php prüfen das und verweigern die direkte
 * Ausführung, falls sie (z. B. per URL) ohne Bootstrap aufgerufen werden —
 * unabhängig davon, ob der Webserver .htaccess/Rewrite-Regeln auswertet.
 */
defined('APP_BOOTSTRAPPED') || define('APP_BOOTSTRAPPED', true);

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
