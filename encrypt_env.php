<?php
/**
 * Teamverwaltung – Werte für die .env verschlüsseln
 *
 * Aufruf (CLI):
 *   php encrypt_env.php "MeinGeheimerWert"
 *
 * Das Ergebnis (ENC:...) in die .env eintragen, z. B. DB_PASS=ENC:....
 * Voraussetzung: APP_SECRET_KEY in der .env (oder eine Key-Datei, siehe README)
 * muss bereits gesetzt sein.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Nur per CLI ausführbar.');
}

require_once __DIR__ . '/src/Env.php';

if (empty($argv[1])) {
    echo "Verwendung: php encrypt_env.php \"ZuVerschlüsselnderWert\"\n";
    exit(1);
}

try {
    $encrypted = Env::encrypt($argv[1]);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "\nVerschlüsselt: {$encrypted}\n\n";
echo "→ In .env eintragen, z. B.:\n";
echo "  DB_PASS={$encrypted}\n\n";
