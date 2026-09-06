<?php

defined('APP_BOOTSTRAPPED') || exit('Direct access not permitted.');

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        die('Ungültiges Formular-Token (CSRF). Bitte Seite neu laden und erneut versuchen.');
    }
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function fmt_datetime(?string $value): string
{
    if (!$value) return '';
    $ts = strtotime($value);
    if (!$ts) return $value;
    return date('d.m.Y H:i', $ts);
}

function input_datetime_value(?string $value): string
{
    if (!$value) return '';
    $ts = strtotime($value);
    if (!$ts) return '';
    return date('Y-m-d\TH:i', $ts);
}

function audit_log(string $action, string $details = ''): void
{
    $user = current_user();
    $stmt = DB::get()->prepare("INSERT INTO audit_log (user_id, action, details) VALUES (?, ?, ?)");
    $stmt->execute([$user['id'] ?? null, $action, $details]);
}

/**
 * Rendert Freitext (Stellenbeschreibung/Anforderungen) als HTML-Absätze: doppelte Zeilenumbrüche
 * trennen Absätze, einfache werden zu <br>. Beginnt eine Zeile mit "Begriff — Erklärung" (Vorbild:
 * GalaxyBot-Anforderungslisten), wird der Teil vor dem Gedankenstrich fett hervorgehoben. Escaped
 * jeden Textbaustein einzeln über e() — die einzigen erzeugten Tags (<p>/<br>/<strong>) sind fest.
 */
function render_rich_text(?string $text): string
{
    if (!$text || trim($text) === '') return '';
    $paragraphs = preg_split('/\n\s*\n/', trim($text));
    $html = '';
    foreach ($paragraphs as $paragraph) {
        $paragraph = trim($paragraph);
        if ($paragraph === '') continue;
        $lineHtml = [];
        foreach (explode("\n", $paragraph) as $line) {
            if (preg_match('/^(.+?)\s+—\s+(.+)$/u', $line, $m)) {
                $lineHtml[] = '<strong>' . e($m[1]) . '</strong> — ' . e($m[2]);
            } else {
                $lineHtml[] = e($line);
            }
        }
        $html .= '<p>' . implode('<br>', $lineHtml) . '</p>';
    }
    return $html;
}

function base_path(): string
{
    return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
}

function url(string $path = ''): string
{
    return base_path() . '/' . ltrim($path, '/');
}
