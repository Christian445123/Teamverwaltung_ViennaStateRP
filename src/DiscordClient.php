<?php

defined('APP_BOOTSTRAPPED') || exit('Direct access not permitted.');

class DiscordClient
{
    private const API = 'https://discord.com/api/v10';

    public static function getAuthorizeUrl(): ?string
    {
        $clientId = Settings::get('discord_client_id');
        if (!$clientId) return null;
        $params = [
            'client_id' => $clientId,
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => 'identify email',
            'prompt' => 'consent',
        ];
        return 'https://discord.com/oauth2/authorize?' . http_build_query($params);
    }

    public static function redirectUri(): string
    {
        return Settings::get('discord_redirect_uri') ?: (Settings::appUrl() . '/discord_callback.php');
    }

    public static function exchangeCode(string $code): ?array
    {
        $clientId = Settings::get('discord_client_id');
        $clientSecret = Settings::get('discord_client_secret');
        if (!$clientId || !$clientSecret) return null;

        $body = http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::redirectUri(),
        ]);

        $result = self::request('POST', 'https://discord.com/api/oauth2/token', $body, [
            'Content-Type: application/x-www-form-urlencoded',
        ]);

        return $result['ok'] ? $result['data'] : null;
    }

    public static function getOAuthUser(string $accessToken): ?array
    {
        $result = self::request('GET', self::API . '/users/@me', null, [
            'Authorization: Bearer ' . $accessToken,
        ]);
        return $result['ok'] ? $result['data'] : null;
    }

    public static function avatarUrl(?string $discordId, ?string $avatarHash): ?string
    {
        if (!$discordId) return null;
        if ($avatarHash) {
            $ext = str_starts_with($avatarHash, 'a_') ? 'gif' : 'png';
            return "https://cdn.discordapp.com/avatars/{$discordId}/{$avatarHash}.{$ext}?size=128";
        }
        $default = intdiv((int) $discordId, 4294967296) % 5;
        return "https://cdn.discordapp.com/embed/avatars/{$default}.png";
    }

    private static function botHeaders(): ?array
    {
        $token = Settings::get('discord_bot_token');
        if (!$token) return null;
        return [
            'Authorization: Bot ' . $token,
            'Content-Type: application/json',
        ];
    }

    public static function getGuildMember(string $discordUserId): ?array
    {
        $headers = self::botHeaders();
        $guildId = Settings::get('discord_guild_id');
        if (!$headers || !$guildId) return null;
        $result = self::request('GET', self::API . "/guilds/{$guildId}/members/{$discordUserId}", null, $headers);
        return $result['ok'] ? $result['data'] : null;
    }

    /** Fetch all members of the configured guild (paginated). */
    public static function fetchGuildMembers(int $limit = 1000): array
    {
        $headers = self::botHeaders();
        $guildId = Settings::get('discord_guild_id');
        if (!$headers || !$guildId) return [];

        $all = [];
        $after = '0';
        do {
            $batchLimit = min(1000, $limit - count($all));
            if ($batchLimit <= 0) break;
            $url = self::API . "/guilds/{$guildId}/members?limit={$batchLimit}&after={$after}";
            $result = self::request('GET', $url, null, $headers);
            if (!$result['ok'] || empty($result['data'])) break;
            $batch = $result['data'];
            foreach ($batch as $m) {
                $all[] = $m;
            }
            if (count($batch) < $batchLimit) break;
            $after = end($batch)['user']['id'] ?? null;
        } while ($after && count($all) < $limit);

        return $all;
    }

    /** Fetch all roles of the configured guild: [id => ['name'=>..,'color'=>..]] */
    public static function fetchGuildRoles(): array
    {
        $headers = self::botHeaders();
        $guildId = Settings::get('discord_guild_id');
        if (!$headers || !$guildId) return [];
        $result = self::request('GET', self::API . "/guilds/{$guildId}/roles", null, $headers);
        return $result['ok'] ? $result['data'] : [];
    }

    /** Fetch text channels of the configured guild. */
    public static function fetchGuildChannels(): array
    {
        $headers = self::botHeaders();
        $guildId = Settings::get('discord_guild_id');
        if (!$headers || !$guildId) return [];
        $result = self::request('GET', self::API . "/guilds/{$guildId}/channels", null, $headers);
        if (!$result['ok']) return [];
        return array_values(array_filter($result['data'], fn($c) => ($c['type'] ?? null) === 0));
    }

    /**
     * Sync a user's Discord roles to match their rank/team mapping.
     * Only touches roles that are known to be "managed" (assigned to a rank or team in our system),
     * so we never remove Discord roles unrelated to this app.
     */
    public static function syncRolesForUser(array $user): array
    {
        $headers = self::botHeaders();
        $guildId = Settings::get('discord_guild_id');
        if (!$headers || !$guildId || empty($user['discord_id'])) {
            return ['ok' => false, 'error' => 'Discord-Bot nicht konfiguriert oder Nutzer nicht verknüpft.'];
        }

        $member = self::getGuildMember($user['discord_id']);
        if (!$member) {
            return ['ok' => false, 'error' => 'Nutzer nicht im Discord-Server gefunden.'];
        }

        $managedRoleIds = self::managedRoleIds();
        $currentRoles = $member['roles'] ?? [];
        $keptRoles = array_values(array_diff($currentRoles, $managedRoleIds));

        $desiredExtra = [];
        $rank = $user['rank_id'] ? self::rankRoleId($user['rank_id']) : null;
        $team = $user['team_id'] ? self::teamRoleId($user['team_id']) : null;
        if ($rank) $desiredExtra[] = $rank;
        if ($team) $desiredExtra[] = $team;
        // Auto-vergebene Zusatzrollen (z. B. "Team") bekommt jedes aktive, verknüpfte Mitglied zusätzlich.
        foreach (self::autoAssignExtraRoleIds() as $extraRoleId) {
            $desiredExtra[] = $extraRoleId;
        }

        $newRoles = array_values(array_unique(array_merge($keptRoles, $desiredExtra)));

        sort($newRoles);
        $sortedCurrent = $currentRoles;
        sort($sortedCurrent);
        if ($newRoles === $sortedCurrent) {
            return ['ok' => true, 'changed' => false];
        }

        $result = self::request('PATCH', self::API . "/guilds/{$guildId}/members/{$user['discord_id']}",
            json_encode(['roles' => $newRoles]), $headers);

        return ['ok' => $result['ok'], 'changed' => true, 'error' => $result['ok'] ? null : ($result['data']['message'] ?? 'Unbekannter Fehler')];
    }

    /**
     * Umgekehrte Richtung zu syncRolesForUser(): ermittelt aus den aktuellen Discord-Rollen
     * eines Nutzers den höchststufigen zugeordneten Rang (ranks.discord_role_id) und stuft den
     * Nutzer dorthin hoch, falls dessen aktueller Rang niedriger ist. Stuft nie automatisch
     * herunter — eine (versehentlich) entfernte Discord-Rolle soll niemanden stillschweigend
     * degradieren, das bleibt eine bewusste Admin-Aktion.
     */
    public static function syncRankFromDiscord(array $user): array
    {
        $member = self::fetchMemberForPull($user);
        if ($member === null) {
            return ['ok' => false, 'changed' => false];
        }
        return self::applyRankFromMember($user, $member);
    }

    /**
     * Führt syncRankFromDiscord() und syncHighTeamFlag() mit nur einer Discord-API-Abfrage
     * zusammen aus. Bevorzugt gegenüber den Einzelmethoden, wenn ohnehin beides gebraucht wird
     * (Login/Verknüpfung, Massen-Sync).
     */
    public static function pullFromDiscord(array $user): array
    {
        $member = self::fetchMemberForPull($user);
        if ($member === null) {
            return ['ok' => false, 'rank' => ['ok' => false, 'changed' => false], 'highTeam' => ['ok' => false, 'changed' => false]];
        }
        return [
            'ok' => true,
            'rank' => self::applyRankFromMember($user, $member),
            'highTeam' => self::applyHighTeamFromMember($user, $member),
        ];
    }

    private static function fetchMemberForPull(array $user): ?array
    {
        if (empty($user['discord_id']) || !self::botHeaders() || !Settings::get('discord_guild_id')) {
            return null;
        }
        return self::getGuildMember($user['discord_id']);
    }

    private static function applyRankFromMember(array $user, array $member): array
    {
        $resolved = self::resolveRankFromRoleIds($member['roles'] ?? []);
        if (!$resolved) {
            return ['ok' => true, 'changed' => false];
        }

        $currentLevel = 0;
        if (!empty($user['rank_id'])) {
            $stmt = DB::get()->prepare("SELECT level FROM ranks WHERE id = ?");
            $stmt->execute([$user['rank_id']]);
            $currentLevel = (int) ($stmt->fetchColumn() ?: 0);
        }

        if ((int) $resolved['level'] <= $currentLevel) {
            return ['ok' => true, 'changed' => false];
        }

        DB::get()->prepare("UPDATE users SET rank_id = ? WHERE id = ?")->execute([$resolved['id'], $user['id']]);

        return ['ok' => true, 'changed' => true, 'rank' => $resolved];
    }

    /** Höchststufiger Rang, dessen discord_role_id in der übergebenen Rollen-Liste enthalten ist. */
    public static function resolveRankFromRoleIds(array $discordRoleIds): ?array
    {
        if (empty($discordRoleIds)) return null;

        $placeholders = implode(',', array_fill(0, count($discordRoleIds), '?'));
        $stmt = DB::get()->prepare("SELECT * FROM ranks WHERE discord_role_id IN ({$placeholders}) ORDER BY level DESC LIMIT 1");
        $stmt->execute(array_values($discordRoleIds));
        return $stmt->fetch() ?: null;
    }

    private static function managedRoleIds(): array
    {
        $db = DB::get();
        $ids = [];
        foreach ($db->query("SELECT discord_role_id FROM ranks WHERE discord_role_id IS NOT NULL AND discord_role_id != ''")->fetchAll() as $r) {
            $ids[] = $r['discord_role_id'];
        }
        foreach ($db->query("SELECT discord_role_id FROM teams WHERE discord_role_id IS NOT NULL AND discord_role_id != ''")->fetchAll() as $r) {
            $ids[] = $r['discord_role_id'];
        }
        // Nur auto-vergebene Zusatzrollen (z. B. "Team") gehören zum verwalteten Set.
        // Rollen mit auto_assign=0 (z. B. "High-Team") werden bewusst NIE angefasst —
        // weder vergeben noch entfernt, siehe autoAssignExtraRoleIds().
        foreach (self::autoAssignExtraRoleIds() as $extraRoleId) {
            $ids[] = $extraRoleId;
        }
        return array_values(array_unique($ids));
    }

    private static function autoAssignExtraRoleIds(): array
    {
        $rows = DB::get()->query("SELECT discord_role_id FROM discord_extra_roles WHERE auto_assign = 1 AND discord_role_id IS NOT NULL AND discord_role_id != ''")->fetchAll();
        return array_values(array_map(fn($r) => $r['discord_role_id'], $rows));
    }

    /** Alle konfigurierten Zusatzrollen (Team/High-Team) inkl. Metadaten, für die Einstellungen-UI. */
    public static function extraRoles(): array
    {
        return DB::get()->query("SELECT * FROM discord_extra_roles ORDER BY slug ASC")->fetchAll();
    }

    /**
     * Liest, ob ein Nutzer aktuell die "High-Team"-Rolle (oder eine andere mit auto_assign=0
     * konfigurierte Zusatzrolle) in Discord hat, und spiegelt das in users.is_high_team —
     * reine Lese-Synchronisierung, die Rolle selbst wird dabei nie verändert.
     */
    public static function syncHighTeamFlag(array $user): array
    {
        $member = self::fetchMemberForPull($user);
        if ($member === null) {
            return ['ok' => false, 'changed' => false];
        }
        return self::applyHighTeamFromMember($user, $member);
    }

    private static function applyHighTeamFromMember(array $user, array $member): array
    {
        $stmt = DB::get()->query("SELECT discord_role_id FROM discord_extra_roles WHERE slug = 'high_team'");
        $highTeamRoleId = $stmt->fetchColumn();
        if (!$highTeamRoleId) {
            return ['ok' => true, 'changed' => false];
        }

        $hasRole = in_array($highTeamRoleId, $member['roles'] ?? [], true) ? 1 : 0;
        if ($hasRole === (int) ($user['is_high_team'] ?? 0)) {
            return ['ok' => true, 'changed' => false];
        }

        DB::get()->prepare("UPDATE users SET is_high_team = ? WHERE id = ?")->execute([$hasRole, $user['id']]);

        return ['ok' => true, 'changed' => true, 'is_high_team' => (bool) $hasRole];
    }

    private static function rankRoleId(int $rankId): ?string
    {
        $stmt = DB::get()->prepare("SELECT discord_role_id FROM ranks WHERE id = ?");
        $stmt->execute([$rankId]);
        $val = $stmt->fetchColumn();
        return $val ?: null;
    }

    private static function teamRoleId(int $teamId): ?string
    {
        $stmt = DB::get()->prepare("SELECT discord_role_id FROM teams WHERE id = ?");
        $stmt->execute([$teamId]);
        $val = $stmt->fetchColumn();
        return $val ?: null;
    }

    /** Create a Discord Scheduled Event for a meeting. Returns the event id or null. */
    public static function createScheduledEvent(array $meeting): ?string
    {
        $headers = self::botHeaders();
        $guildId = Settings::get('discord_guild_id');
        if (!$headers || !$guildId) return null;

        $start = date('c', strtotime($meeting['start_time']));
        $end = $meeting['end_time'] ? date('c', strtotime($meeting['end_time'])) : date('c', strtotime($meeting['start_time']) + 3600);

        $payload = [
            'name' => mb_substr($meeting['title'], 0, 100),
            'description' => mb_substr($meeting['description'] ?? '', 0, 1000),
            'scheduled_start_time' => $start,
            'scheduled_end_time' => $end,
            'privacy_level' => 2,
            'entity_type' => 3,
            'entity_metadata' => ['location' => mb_substr($meeting['location'] ?: 'Online', 0, 100)],
        ];

        $result = self::request('POST', self::API . "/guilds/{$guildId}/scheduled-events", json_encode($payload), $headers);
        return $result['ok'] ? ($result['data']['id'] ?? null) : null;
    }

    public static function updateScheduledEvent(string $eventId, array $meeting): bool
    {
        $headers = self::botHeaders();
        $guildId = Settings::get('discord_guild_id');
        if (!$headers || !$guildId) return false;

        $start = date('c', strtotime($meeting['start_time']));
        $end = $meeting['end_time'] ? date('c', strtotime($meeting['end_time'])) : date('c', strtotime($meeting['start_time']) + 3600);

        $payload = [
            'name' => mb_substr($meeting['title'], 0, 100),
            'description' => mb_substr($meeting['description'] ?? '', 0, 1000),
            'scheduled_start_time' => $start,
            'scheduled_end_time' => $end,
            'entity_metadata' => ['location' => mb_substr($meeting['location'] ?: 'Online', 0, 100)],
        ];
        if (($meeting['status'] ?? '') === 'cancelled') {
            $payload['status'] = 4;
        }

        $result = self::request('PATCH', self::API . "/guilds/{$guildId}/scheduled-events/{$eventId}", json_encode($payload), $headers);
        return $result['ok'];
    }

    public static function deleteScheduledEvent(string $eventId): bool
    {
        $headers = self::botHeaders();
        $guildId = Settings::get('discord_guild_id');
        if (!$headers || !$guildId) return false;
        $result = self::request('DELETE', self::API . "/guilds/{$guildId}/scheduled-events/{$eventId}", null, $headers);
        return $result['ok'];
    }

    /**
     * Post a message announcing a meeting, via webhook (preferred) or bot channel message.
     * When a Discord Public Key is configured (interactions endpoint set up), the message gets
     * Zusagen/Vielleicht/Absagen-Buttons, mit denen direkt in Discord geantwortet werden kann —
     * ohne Portal-Login (siehe discord_interactions.php).
     */
    public static function announceMeeting(array $meeting): ?string
    {
        $content = "📅 **Neue Besprechung:** {$meeting['title']}\n"
            . "🕒 " . date('d.m.Y H:i', strtotime($meeting['start_time'])) . " Uhr\n"
            . ($meeting['location'] ? "📍 {$meeting['location']}\n" : '')
            . ($meeting['description'] ? "\n{$meeting['description']}" : '');

        $payload = ['content' => $content];
        if (!empty($meeting['id']) && Settings::get('discord_public_key')) {
            $payload['components'] = [self::rsvpActionRow((int) $meeting['id'])];
        }

        $webhook = Settings::get('discord_webhook_url');
        if ($webhook) {
            $result = self::request('POST', $webhook . '?wait=true', json_encode($payload), ['Content-Type: application/json']);
            return $result['ok'] ? (string) ($result['data']['id'] ?? '') : null;
        }

        $headers = self::botHeaders();
        $channelId = Settings::get('discord_announce_channel_id');
        if ($headers && $channelId) {
            $result = self::request('POST', self::API . "/channels/{$channelId}/messages", json_encode($payload), $headers);
            return $result['ok'] ? (string) ($result['data']['id'] ?? '') : null;
        }

        return null;
    }

    private static function rsvpActionRow(int $meetingId): array
    {
        return [
            'type' => 1, // Action Row
            'components' => [
                ['type' => 2, 'style' => 3, 'label' => 'Zusagen', 'emoji' => ['name' => '✅'], 'custom_id' => "rsvp:{$meetingId}:accepted"],
                ['type' => 2, 'style' => 2, 'label' => 'Vielleicht', 'emoji' => ['name' => '❔'], 'custom_id' => "rsvp:{$meetingId}:maybe"],
                ['type' => 2, 'style' => 4, 'label' => 'Absagen', 'emoji' => ['name' => '❌'], 'custom_id' => "rsvp:{$meetingId}:declined"],
            ],
        ];
    }

    private static function request(string $method, string $url, $body = null, array $headers = []): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['ok' => false, 'data' => null, 'error' => $error];
        }

        $decoded = json_decode($response, true);
        $ok = $status >= 200 && $status < 300;
        return ['ok' => $ok, 'data' => $decoded, 'status' => $status];
    }
}
