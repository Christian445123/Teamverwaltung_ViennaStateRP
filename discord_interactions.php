<?php
/**
 * Discord Interactions Endpoint: verarbeitet Klicks auf die Teilnehmen/Vielleicht/Absagen-
 * Buttons unter Besprechungs-Ankündigungen — ganz ohne Portal-Login. Als "Interactions
 * Endpoint URL" im Discord Developer Portal eintragen (General Information → Public Key
 * dorthin kopieren als DISCORD_PUBLIC_KEY in die .env).
 *
 * Jede Anfrage wird per Ed25519-Signatur verifiziert (Discord-Vorgabe) — ohne gültige
 * Signatur wird sofort mit 401 abgebrochen, unabhängig vom Inhalt.
 *
 * WICHTIG: Discord verlangt eine Antwort innerhalb von 3 Sekunden, sonst zeigt der Client
 * "hat nicht rechtzeitig reagiert". Statt die komplette Verarbeitung (DB, ggf. Nutzer
 * anlegen) in dieses enge Zeitfenster zu quetschen, wird nach Discords eigenem Muster für
 * langsame Interaktionen gearbeitet:
 *   1. Signatur so früh wie möglich prüfen (nur .env lesen, keine DB-Verbindung nötig).
 *   2. Sofort mit type=5 (DEFERRED_CHANNEL_MESSAGE_WITH_SOURCE) antworten und die
 *      Verbindung zum Client aktiv beenden (fastcgi_finish_request) — das dauert nur
 *      Millisekunden und zeigt dem Nutzer in Discord ein "Bot denkt nach …".
 *   3. Danach in Ruhe die eigentliche Logik ausführen (bis zu 15 Minuten Zeit) und das
 *      Ergebnis per Follow-up-Request (PATCH .../messages/@original) nachreichen.
 * Ein PING (Discords Verbindungstest beim Speichern der Endpoint-URL) wird weiterhin
 * sofort synchron beantwortet, ohne die DB überhaupt zu berühren.
 */

header('Content-Type: application/json');

define('APP_BOOTSTRAPPED', true);
require_once __DIR__ . '/src/Env.php';
Env::load();

$publicKeyHex = Env::get('DISCORD_PUBLIC_KEY');
$signature = $_SERVER['HTTP_X_SIGNATURE_ED25519'] ?? '';
$timestamp = $_SERVER['HTTP_X_SIGNATURE_TIMESTAMP'] ?? '';
$rawBody = file_get_contents('php://input');

if (!$publicKeyHex || !$signature || !$timestamp || !function_exists('sodium_crypto_sign_verify_detached')) {
    http_response_code(401);
    exit;
}

try {
    $valid = sodium_crypto_sign_verify_detached(
        hex2bin($signature),
        $timestamp . $rawBody,
        hex2bin($publicKeyHex)
    );
} catch (\Throwable $e) {
    $valid = false;
}

if (!$valid) {
    http_response_code(401);
    exit;
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    exit;
}

// PING – Discord prüft die Endpoint-URL beim Speichern damit. Keine DB nötig.
if (($payload['type'] ?? null) === 1) {
    echo json_encode(['type' => 1]);
    exit;
}

// Alles außer MESSAGE_COMPONENT (Button-Klick) kennen wir nicht.
if (($payload['type'] ?? null) !== 3) {
    http_response_code(400);
    exit;
}

// Sofort quittieren ("Bot denkt nach …", ephemeral) und die Verbindung beenden — alles
// Weitere unten passiert danach, ohne Zeitdruck.
echo json_encode(['type' => 5, 'data' => ['flags' => 64]]);
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    if (ob_get_level() > 0) { ob_end_flush(); }
    flush();
}

/** Schickt das endgültige Ergebnis als Follow-up (ersetzt das "denkt nach …"). */
function send_followup(string $applicationId, string $interactionToken, string $content): void
{
    $url = "https://discord.com/api/v10/webhooks/{$applicationId}/{$interactionToken}/messages/@original";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => json_encode(['content' => $content]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

try {
    require __DIR__ . '/bootstrap.php';

    $applicationId = Settings::get('discord_client_id');
    $interactionToken = $payload['token'] ?? '';
    if (!$applicationId || !$interactionToken) {
        exit; // kann kein Follow-up mehr schicken, aber der Klick selbst war valide signiert
    }

    $customId = $payload['data']['custom_id'] ?? '';
    $parts = explode(':', $customId, 3);

    if (count($parts) !== 3 || $parts[0] !== 'rsvp') {
        send_followup($applicationId, $interactionToken, 'Unbekannte Aktion.');
        exit;
    }
    [, $meetingIdRaw, $status] = $parts;
    $meetingId = (int) $meetingIdRaw;

    if (!in_array($status, ['accepted', 'maybe', 'declined'], true)) {
        send_followup($applicationId, $interactionToken, 'Ungültiger Status.');
        exit;
    }

    $discordMember = $payload['member'] ?? null;
    $discordUser = $discordMember['user'] ?? ($payload['user'] ?? null);
    if (!$discordUser || empty($discordUser['id'])) {
        send_followup($applicationId, $interactionToken, 'Konnte deinen Discord-Account nicht ermitteln.');
        exit;
    }

    $db = DB::get();

    $stmt = $db->prepare("SELECT * FROM meetings WHERE id = ?");
    $stmt->execute([$meetingId]);
    $meeting = $stmt->fetch();
    if (!$meeting) {
        send_followup($applicationId, $interactionToken, 'Diese Besprechung existiert nicht mehr.');
        exit;
    }
    if ($meeting['status'] === 'cancelled') {
        send_followup($applicationId, $interactionToken, 'Diese Besprechung wurde abgesagt.');
        exit;
    }

    // Nur Mitglieder mit der "Team"-Rolle dürfen antworten (falls eine konfiguriert ist).
    $teamRoleId = $db->query("SELECT discord_role_id FROM discord_extra_roles WHERE slug = 'team'")->fetchColumn();
    $memberRoles = $discordMember['roles'] ?? [];
    if ($teamRoleId && !in_array($teamRoleId, $memberRoles, true)) {
        send_followup($applicationId, $interactionToken, 'Du bist nicht berechtigt, auf Besprechungen zu antworten.');
        exit;
    }

    // Nutzer anhand der Discord-ID finden oder (minimal) neu anlegen — RSVP funktioniert
    // dadurch auch für Mitglieder, die sich noch nie im Portal angemeldet haben.
    $stmt = $db->prepare("SELECT * FROM users WHERE discord_id = ?");
    $stmt->execute([$discordUser['id']]);
    $user = $stmt->fetch();
    if (!$user) {
        $lowestRank = $db->query("SELECT id FROM ranks ORDER BY level ASC LIMIT 1")->fetchColumn();
        $stmt = $db->prepare("INSERT INTO users (discord_id, discord_username, discord_avatar, display_name, rank_id, status)
            VALUES (?, ?, ?, ?, ?, 'active')");
        $stmt->execute([
            $discordUser['id'],
            $discordUser['username'] ?? null,
            $discordUser['avatar'] ?? null,
            $discordUser['global_name'] ?? ($discordUser['username'] ?? 'Discord-Nutzer'),
            $lowestRank ?: null,
        ]);
        $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$db->lastInsertId()]);
        $user = $stmt->fetch();
    }

    if (!Perm::has($user, 'meetings.respond')) {
        send_followup($applicationId, $interactionToken, 'Dir fehlt die Berechtigung, auf Besprechungen zu antworten.');
        exit;
    }

    $stmt = $db->prepare("INSERT INTO meeting_attendees (meeting_id, user_id, status, responded_at) VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE status = VALUES(status), responded_at = VALUES(responded_at)");
    $stmt->execute([$meetingId, $user['id'], $status]);

    $labels = [
        'accepted' => '✅ Du hast zugesagt!',
        'maybe' => '❔ Als „vielleicht" vermerkt.',
        'declined' => '❌ Du hast abgesagt.',
    ];
    send_followup($applicationId, $interactionToken, $labels[$status]);
} catch (\Throwable $e) {
    error_log('[discord_interactions] ' . $e->getMessage());
    if (!empty($applicationId) && !empty($interactionToken)) {
        send_followup($applicationId, $interactionToken, 'Es ist ein Fehler aufgetreten. Bitte später erneut versuchen oder im Dashboard antworten.');
    }
}
