<?php
require __DIR__ . '/bootstrap.php';

$code = $_GET['code'] ?? null;
$state = $_GET['state'] ?? null;
$expectedState = $_SESSION['discord_oauth_state'] ?? null;
$linkMode = !empty($_SESSION['discord_link_mode']);
unset($_SESSION['discord_oauth_state'], $_SESSION['discord_link_mode']);

if (!$code || !$state || !$expectedState || !hash_equals($expectedState, $state)) {
    flash('error', 'Discord-Anmeldung fehlgeschlagen (ungültiger Status).');
    redirect(url('login.php'));
}

$token = DiscordClient::exchangeCode($code);
if (!$token || empty($token['access_token'])) {
    flash('error', 'Discord-Anmeldung fehlgeschlagen. Bitte erneut versuchen.');
    redirect(url('login.php'));
}

$discordUser = DiscordClient::getOAuthUser($token['access_token']);
if (!$discordUser || empty($discordUser['id'])) {
    flash('error', 'Discord-Profil konnte nicht geladen werden.');
    redirect(url('login.php'));
}

$db = DB::get();

// Gesperrte Mitglieder abfangen, BEVOR die normale Logik unten greift — sonst würde die Suche
// nach "WHERE discord_id = ? AND status = 'active' AND is_banned = 0" weiter unten niemanden
// finden und stattdessen (im Login-Fall) ein zweites, ungesperrtes Konto für dieselbe Person
// anlegen. Läuft unabhängig von $linkMode, damit sich Gesperrte auch nicht per Verknüpfung
// wieder Zugriff verschaffen können.
$bannedCheck = $db->prepare("SELECT is_banned FROM users WHERE discord_id = ? AND is_banned = 1");
$bannedCheck->execute([$discordUser['id']]);
if ($bannedCheck->fetch()) {
    flash('error', 'Dieser Discord-Account ist gesperrt.');
    redirect(url('login.php'));
}

if ($linkMode) {
    $me = Auth::requireLogin();

    $existing = $db->prepare("SELECT id FROM users WHERE discord_id = ? AND id != ?");
    $existing->execute([$discordUser['id'], $me['id']]);
    if ($existing->fetch()) {
        flash('error', 'Dieser Discord-Account ist bereits mit einem anderen Mitglied verknüpft.');
        redirect(url('profile.php'));
    }

    $stmt = $db->prepare("UPDATE users SET discord_id = ?, discord_username = ?, discord_avatar = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$discordUser['id'], $discordUser['username'], $discordUser['avatar'], $me['id']]);
    audit_log('auth.discord_link', $discordUser['username'] ?? $discordUser['id']);

    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$me['id']]);
    $linkedUser = $stmt->fetch();
    $pull = DiscordClient::pullFromDiscord($linkedUser);

    $extraMsg = '';
    if (!empty($pull['rank']['changed'])) {
        $extraMsg .= ' Dein Rang wurde anhand deiner Discord-Rollen auf "' . $pull['rank']['rank']['name'] . '" gesetzt.';
    }
    flash('success', 'Dein Discord-Account wurde verknüpft.' . $extraMsg);
    redirect(url('profile.php'));
}

$stmt = $db->prepare("SELECT * FROM users WHERE discord_id = ? AND status = 'active' AND is_banned = 0");
$stmt->execute([$discordUser['id']]);
$user = $stmt->fetch();

$isNewAccount = !$user;
if (!$user) {
    $lowestRank = $db->query("SELECT id FROM ranks ORDER BY level ASC LIMIT 1")->fetchColumn();
    $isFirstUser = !Auth::hasAnyUsers();
    $stmt = $db->prepare("INSERT INTO users (discord_id, discord_username, discord_avatar, display_name, rank_id, is_superadmin, status)
        VALUES (?, ?, ?, ?, ?, ?, 'active')");
    $stmt->execute([
        $discordUser['id'],
        $discordUser['username'],
        $discordUser['avatar'],
        $discordUser['global_name'] ?? $discordUser['username'],
        $lowestRank ?: null,
        $isFirstUser ? 1 : 0,
    ]);
    $stmt2 = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt2->execute([$db->lastInsertId()]);
    $user = $stmt2->fetch();
    flash('success', 'Willkommen! Dein Konto wurde über Discord erstellt.');
} else {
    $stmt = $db->prepare("UPDATE users SET discord_username = ?, discord_avatar = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$discordUser['username'], $discordUser['avatar'], $user['id']]);
}

$pull = DiscordClient::pullFromDiscord($user);
if (!empty($pull['rank']['changed']) || !empty($pull['highTeam']['changed'])) {
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $user = $stmt->fetch();
    if (!empty($pull['rank']['changed'])) {
        flash('success', 'Dein Rang wurde anhand deiner Discord-Rollen auf "' . $pull['rank']['rank']['name'] . '" gesetzt.');
    }
}

Auth::login($user);
audit_log($isNewAccount ? 'auth.discord_signup' : 'auth.login', 'Login per Discord');
redirect(url('index.php'));
