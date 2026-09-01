<?php
/**
 * Discord Interactions Endpoint: verarbeitet Klicks auf die Teilnehmen/Vielleicht/Absagen-
 * Buttons unter Besprechungs-Ankündigungen — ganz ohne Portal-Login. Als "Interactions
 * Endpoint URL" im Discord Developer Portal eintragen (General Information → Public Key
 * dorthin kopieren als DISCORD_PUBLIC_KEY in die .env).
 *
 * Jede Anfrage wird per Ed25519-Signatur verifiziert (Discord-Vorgabe) — ohne gültige
 * Signatur wird sofort mit 401 abgebrochen, unabhängig vom Inhalt.
 */
require __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

function ephemeral_response(string $content): array
{
    return ['type' => 4, 'data' => ['content' => $content, 'flags' => 64]];
}

$publicKeyHex = Settings::get('discord_public_key');
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

// PING – Discord prüft die Endpoint-URL beim Speichern damit.
if (($payload['type'] ?? null) === 1) {
    echo json_encode(['type' => 1]);
    exit;
}

// MESSAGE_COMPONENT – ein Button wurde geklickt.
if (($payload['type'] ?? null) === 3) {
    $customId = $payload['data']['custom_id'] ?? '';
    $parts = explode(':', $customId, 3);

    if (count($parts) !== 3 || $parts[0] !== 'rsvp') {
        echo json_encode(ephemeral_response('Unbekannte Aktion.'));
        exit;
    }
    [, $meetingIdRaw, $status] = $parts;
    $meetingId = (int) $meetingIdRaw;

    if (!in_array($status, ['accepted', 'maybe', 'declined'], true)) {
        echo json_encode(ephemeral_response('Ungültiger Status.'));
        exit;
    }

    $discordMember = $payload['member'] ?? null;
    $discordUser = $discordMember['user'] ?? ($payload['user'] ?? null);
    if (!$discordUser || empty($discordUser['id'])) {
        echo json_encode(ephemeral_response('Konnte deinen Discord-Account nicht ermitteln.'));
        exit;
    }

    $db = DB::get();

    $stmt = $db->prepare("SELECT * FROM meetings WHERE id = ?");
    $stmt->execute([$meetingId]);
    $meeting = $stmt->fetch();
    if (!$meeting) {
        echo json_encode(ephemeral_response('Diese Besprechung existiert nicht mehr.'));
        exit;
    }
    if ($meeting['status'] === 'cancelled') {
        echo json_encode(ephemeral_response('Diese Besprechung wurde abgesagt.'));
        exit;
    }

    // Nur Mitglieder mit der "Team"-Rolle dürfen antworten (falls eine konfiguriert ist).
    $teamRoleId = $db->query("SELECT discord_role_id FROM discord_extra_roles WHERE slug = 'team'")->fetchColumn();
    $memberRoles = $discordMember['roles'] ?? [];
    if ($teamRoleId && !in_array($teamRoleId, $memberRoles, true)) {
        echo json_encode(ephemeral_response('Du bist nicht berechtigt, auf Besprechungen zu antworten.'));
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
        echo json_encode(ephemeral_response('Dir fehlt die Berechtigung, auf Besprechungen zu antworten.'));
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
    echo json_encode(ephemeral_response($labels[$status]));
    exit;
}

http_response_code(400);
