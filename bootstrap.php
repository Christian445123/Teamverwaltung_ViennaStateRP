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

/**
 * Meldet unerwartete technische Fehler (uncaught Exceptions, PHP-Warnings/-Errors, Fatal
 * Errors) automatisch an DiscordClient::postDevLog() (DISCORD_DEV_LOG_WEBHOOK_URL) — zusätzlich
 * zum normalen PHP-Error-Log, nie stattdessen. Vor DB::get() registriert, damit selbst ein
 * DB-Verbindungsfehler noch gemeldet wird. $reporting verhindert eine Endlosschleife, falls
 * das Melden selbst einen PHP-Warning auslöst (z. B. durch curl).
 */
$reporting = false;

set_exception_handler(function (\Throwable $e) use (&$reporting) {
    error_log('[uncaught] ' . $e);
    if ($reporting) return;
    $reporting = true;
    DiscordClient::postDevLog(get_class($e) . ': ' . $e->getMessage(), $e->getFile() . ':' . $e->getLine());
    $reporting = false;
});

set_error_handler(function (int $severity, string $message, string $file = '', int $line = 0) use (&$reporting) {
    if (!(error_reporting() & $severity)) return false;
    $reportable = [E_WARNING, E_USER_ERROR, E_USER_WARNING, E_RECOVERABLE_ERROR];
    if (!$reporting && in_array($severity, $reportable, true)) {
        $reporting = true;
        DiscordClient::postDevLog('PHP-Warning/-Error', "{$message}\n{$file}:{$line}");
        $reporting = false;
    }
    return false; // normale PHP-Fehlerbehandlung (error_log etc.) läuft unverändert weiter
});

register_shutdown_function(function () use (&$reporting) {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && !$reporting) {
        DiscordClient::postDevLog('Fatal Error', "{$error['message']}\n{$error['file']}:{$error['line']}");
    }
});

// Warm up the DB connection (also runs migrations on first request).
DB::get();

function current_user(): ?array
{
    return Auth::currentUser();
}
