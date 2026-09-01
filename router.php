<?php
/**
 * Nur für den lokalen Entwicklungsserver: `php -S localhost:8000 router.php`.
 * Der eingebaute PHP-Server wertet keine .htaccess aus — dieser Router bildet
 * die wichtigsten Sperren von dort nach (Dotfiles, src/, includes/), damit
 * z. B. http://localhost:8000/.env lokal nicht einfach abrufbar ist.
 * Auf echten Servern (Apache/nginx) wird diese Datei nicht verwendet.
 */

$path = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

if (preg_match('#^/\.|^/(src|includes)(/|$)#', $path)) {
    http_response_code(403);
    exit('Forbidden');
}

return false;
