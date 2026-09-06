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
            // Datenminimierung (Art. 5 Abs. 1 lit. c DSGVO): nur "identify" anfordern — die
            // E-Mail-Adresse wird von der Teamverwaltung nirgends ausgelesen/gespeichert.
            'scope' => 'identify',
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

    /**
     * Best-effort-Zuordnung eines frei eingegebenen Discord-Tags (aus einer Bewerbung) zu einem
     * echten Server-Mitglied, damit ein bei Annahme automatisch angelegtes Mitgliedskonto gleich
     * die richtige discord_id bekommt — sonst würde ein späterer Discord-Login der Person ein
     * zweites, unverknüpftes Konto anlegen (Login matched ausschließlich per discord_id).
     * Akzeptiert sowohl neue Nutzernamen als auch alte "Name#1234"-Tags. Liefert nur bei genau
     * einem eindeutigen Treffer ein Ergebnis, sonst null (lieber unverknüpft als falsch verknüpft).
     */
    public static function findGuildMemberByTag(string $tag): ?array
    {
        $namePart = strtolower(trim(ltrim(trim($tag), '@')));
        $namePart = explode('#', $namePart)[0];
        if ($namePart === '') return null;

        $matches = [];
        foreach (self::fetchGuildMembers() as $m) {
            $u = $m['user'] ?? null;
            if (!$u) continue;
            $username = strtolower($u['username'] ?? '');
            $globalName = strtolower($u['global_name'] ?? '');
            if ($username === $namePart || $globalName === $namePart) {
                $matches[] = $u;
            }
        }
        return count($matches) === 1 ? $matches[0] : null;
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

        // "High-Team" impliziert "Team" — vor der eigentlichen Prüfung nachtragen, falls nötig.
        self::ensureTeamRoleForHighTeam($user, $member);

        // Rang-/Team-Rollen werden über das Dashboard nur zugewiesen, wenn die Person auf
        // Discord bereits die "Team"-Rolle hat. Die "Team"-Rolle selbst vergibt die
        // Teamverwaltung sonst nie — die wird ausschließlich manuell in Discord gepflegt.
        if (!self::memberHasTeamRole($member)) {
            return ['ok' => false, 'error' => 'Nutzer hat nicht die "Team"-Rolle auf Discord — keine Rollenzuweisung.'];
        }

        $managedRoleIds = self::managedRoleIds();
        $currentRoles = $member['roles'] ?? [];
        $keptRoles = array_values(array_diff($currentRoles, $managedRoleIds));

        $desiredExtra = [];
        $rank = $user['rank_id'] ? self::rankRoleId($user['rank_id']) : null;
        if ($rank) $desiredExtra[] = $rank;
        foreach (self::teamRoleIdsForUser((int) $user['id']) as $teamRole) {
            $desiredExtra[] = $teamRole;
        }
        // Auto-vergebene Zusatzrollen (aktuell keine — "Team" wird bewusst nie automatisch
        // vergeben, siehe oben) kämen hier zusätzlich dazu.
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
            return [
                'ok' => false,
                'rank' => ['ok' => false, 'changed' => false],
                'highTeam' => ['ok' => false, 'changed' => false],
                'team' => ['ok' => false, 'changed' => false],
                'permTags' => ['ok' => false, 'changed' => false],
            ];
        }
        return [
            'ok' => true,
            'rank' => self::applyRankFromMember($user, $member),
            'highTeam' => self::applyHighTeamFromMember($user, $member),
            'team' => self::applyTeamFlagFromMember($user, $member),
            'permTags' => self::applyPermTagsFromMember($user, $member),
        ];
    }

    /**
     * Lädt den Discord-Member für eine der Pull-Operationen (Rang, High-Team) — gibt
     * absichtlich null zurück, wenn die Person weder "Team" noch "High-Team" hat: es soll
     * niemand synchronisiert werden, der auf Discord nicht (mehr) im Team ist. "High-Team"
     * reicht hier bewusst schon aus (nicht nur "Team"), damit applyHighTeamFromMember()
     * für solche Personen erreichbar bleibt und ihnen "Team" nachträgt (siehe dort) — die
     * strengere "nur Team"-Prüfung für die eigentliche Rang-Rollen-Vergabe passiert erst in
     * syncRolesForUser() bzw. beim Auflösen des Rangs.
     */
    private static function fetchMemberForPull(array $user): ?array
    {
        if (empty($user['discord_id']) || !self::botHeaders() || !Settings::get('discord_guild_id')) {
            return null;
        }
        $member = self::getGuildMember($user['discord_id']);
        if ($member === null || !self::memberIsSyncEligible($member)) {
            return null;
        }
        return $member;
    }

    private static function memberHasTeamRole(array $member): bool
    {
        return self::memberHasExtraRole($member, 'team');
    }

    private static function memberHasHighTeamRole(array $member): bool
    {
        return self::memberHasExtraRole($member, 'high_team');
    }

    /** Team ODER High-Team — der breitere Kreis, der überhaupt synchronisiert wird. */
    private static function memberIsSyncEligible(array $member): bool
    {
        $teamId = self::extraRoleId('team');
        $highTeamId = self::extraRoleId('high_team');
        if (!$teamId && !$highTeamId) {
            return true; // keine der beiden Rollen zugeordnet → Gate deaktiviert (altes Verhalten)
        }
        $roles = $member['roles'] ?? [];
        return ($teamId && in_array($teamId, $roles, true)) || ($highTeamId && in_array($highTeamId, $roles, true));
    }

    private static function memberHasExtraRole(array $member, string $slug): bool
    {
        $roleId = self::extraRoleId($slug);
        if (!$roleId) {
            return true; // Rolle noch nicht zugeordnet → Gate deaktiviert (altes Verhalten)
        }
        return in_array($roleId, $member['roles'] ?? [], true);
    }

    private static function extraRoleId(string $slug): ?string
    {
        $stmt = DB::get()->prepare("SELECT discord_role_id FROM discord_extra_roles WHERE slug = ?");
        $stmt->execute([$slug]);
        $val = $stmt->fetchColumn();
        return $val ?: null;
    }

    /**
     * "High-Team" impliziert "Team": wer High-Team hat, aber (noch) nicht Team, bekommt Team
     * über den Bot nachgetragen — Team wird von der Teamverwaltung sonst nirgends vergeben,
     * das ist die eine bewusste Ausnahme. Aktualisiert $member['roles'] bei Erfolg direkt,
     * damit nachfolgende Prüfungen im selben Durchlauf den neuen Stand sehen.
     */
    private static function ensureTeamRoleForHighTeam(array $user, array &$member): void
    {
        if (empty($user['discord_id'])) return;
        if (!self::memberHasHighTeamRole($member)) return;
        if (self::memberHasTeamRole($member)) return;

        $teamRoleId = self::extraRoleId('team');
        if (!$teamRoleId) return;

        $headers = self::botHeaders();
        $guildId = Settings::get('discord_guild_id');
        if (!$headers || !$guildId) return;

        $result = self::request('PUT', self::API . "/guilds/{$guildId}/members/{$user['discord_id']}/roles/{$teamRoleId}", null, $headers);
        if ($result['ok']) {
            $member['roles'][] = $teamRoleId;
        }
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
        // Nur Zusatzrollen mit auto_assign=1 gehören zum verwalteten Set. "Team" und
        // "High-Team" haben beide auto_assign=0 und werden bewusst NIE angefasst — weder
        // vergeben noch entfernt, siehe autoAssignExtraRoleIds() / memberHasTeamRole().
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

    private static function applyHighTeamFromMember(array $user, array &$member): array
    {
        $highTeamRoleId = self::extraRoleId('high_team');
        if (!$highTeamRoleId) {
            return ['ok' => true, 'changed' => false];
        }

        $hasRole = in_array($highTeamRoleId, $member['roles'] ?? [], true) ? 1 : 0;

        // "High-Team" impliziert "Team" — nachtragen, falls noch nicht vorhanden.
        if ($hasRole) {
            self::ensureTeamRoleForHighTeam($user, $member);
        }

        if ($hasRole === (int) ($user['is_high_team'] ?? 0)) {
            return ['ok' => true, 'changed' => false];
        }

        DB::get()->prepare("UPDATE users SET is_high_team = ? WHERE id = ?")->execute([$hasRole, $user['id']]);

        return ['ok' => true, 'changed' => true, 'is_high_team' => (bool) $hasRole];
    }

    /** Spiegelt den aktuellen "Team"-Status (analog zu High-Team) in users.is_team. */
    private static function applyTeamFlagFromMember(array $user, array $member): array
    {
        $hasRole = self::memberHasTeamRole($member) ? 1 : 0;
        if ($hasRole === (int) ($user['is_team'] ?? 0)) {
            return ['ok' => true, 'changed' => false];
        }

        DB::get()->prepare("UPDATE users SET is_team = ? WHERE id = ?")->execute([$hasRole, $user['id']]);

        return ['ok' => true, 'changed' => true, 'is_team' => (bool) $hasRole];
    }

    /** Alle konfigurierten Zusatzrollen (Administrator, Ban, Kick, …) für die Einstellungen-UI. */
    public static function permTags(): array
    {
        return DB::get()->query("SELECT * FROM discord_perm_tags ORDER BY sort_order ASC")->fetchAll();
    }

    /** Aktuell gehaltene Zusatzrollen-Slugs eines Mitglieds, für die Anzeige als Badges. */
    public static function permTagsOfUser(int $userId): array
    {
        $stmt = DB::get()->prepare("SELECT tag_slug FROM user_perm_tags WHERE user_id = ?");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Spiegelt, welche der konfigurierten Zusatzrollen (discord_perm_tags) ein Mitglied
     * aktuell auf Discord hat, in user_perm_tags — voller Abgleich (nicht additiv wie beim
     * Rang): was fehlt wird ergänzt, was nicht mehr da ist wird entfernt. Reine Kennzeichnung
     * ohne jede Auswirkung auf Teamverwaltung-Berechtigungen; die Teamverwaltung vergibt diese
     * Rollen selbst nie.
     */
    private static function applyPermTagsFromMember(array $user, array $member): array
    {
        $tags = self::permTags();
        $roles = $member['roles'] ?? [];
        $current = self::permTagsOfUser((int) $user['id']);

        $desired = [];
        foreach ($tags as $tag) {
            if ($tag['discord_role_id'] && in_array($tag['discord_role_id'], $roles, true)) {
                $desired[] = $tag['slug'];
            }
        }

        $toAdd = array_diff($desired, $current);
        $toRemove = array_diff($current, $desired);
        if (!$toAdd && !$toRemove) {
            return ['ok' => true, 'changed' => false];
        }

        $db = DB::get();
        $insert = $db->prepare("INSERT IGNORE INTO user_perm_tags (user_id, tag_slug) VALUES (?, ?)");
        foreach ($toAdd as $slug) {
            $insert->execute([$user['id'], $slug]);
        }
        if ($toRemove) {
            $placeholders = implode(',', array_fill(0, count($toRemove), '?'));
            $db->prepare("DELETE FROM user_perm_tags WHERE user_id = ? AND tag_slug IN ({$placeholders})")
                ->execute(array_merge([$user['id']], array_values($toRemove)));
        }

        return ['ok' => true, 'changed' => true, 'tags' => $desired];
    }

    private static function rankRoleId(int $rankId): ?string
    {
        $stmt = DB::get()->prepare("SELECT discord_role_id FROM ranks WHERE id = ?");
        $stmt->execute([$rankId]);
        $val = $stmt->fetchColumn();
        return $val ?: null;
    }

    /** Discord-Rollen-IDs aller Teams, denen ein Mitglied aktuell zugeordnet ist (0, 1 oder mehrere). */
    private static function teamRoleIdsForUser(int $userId): array
    {
        $stmt = DB::get()->prepare("
            SELECT t.discord_role_id FROM user_teams ut
            JOIN teams t ON t.id = ut.team_id
            WHERE ut.user_id = ? AND t.discord_role_id IS NOT NULL AND t.discord_role_id != ''
        ");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
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
     * Post a message announcing a meeting, via bot channel message or webhook.
     * Als Embed mit Vom/Bis/Ort/Thema/Inhalt-Feldern, pingt die Discord-Rollen aller
     * eingeladenen Ränge (+ Team-Rolle bei team-gebundenen Besprechungen). Ist ein Discord
     * Public Key konfiguriert (Interactions Endpoint eingerichtet), bekommt die Nachricht
     * Teilnehmen/Vielleicht/Absagen-Buttons, mit denen direkt in Discord geantwortet werden
     * kann — ohne Portal-Login (siehe discord_interactions.php). Ein "Zum Meeting"-Link-Button
     * (führt ins Dashboard) wird immer angehängt.
     *
     * Transport-Wahl: Ein normaler Kanal-Webhook liefert Klicks auf Buttons mit eigener
     * custom_id NICHT zuverlässig an unseren Interactions Endpoint aus — nur eine vom Bot
     * selbst gesendete Nachricht ist sicher mit unserer Application verknüpft. Werden
     * interaktive Buttons gebraucht (Public Key gesetzt) und ist ein Bot-Kanal konfiguriert,
     * wird deshalb der Bot bevorzugt, auch wenn zusätzlich ein Webhook konfiguriert ist.
     * Ohne Bot-Kanal wird über den Webhook nur der (immer funktionierende) Link-Button
     * mitgeschickt, damit keine kaputten Buttons angezeigt werden.
     */
    public static function announceMeeting(array $meeting): ?array
    {
        $meetingId = (int) ($meeting['id'] ?? 0);
        $embed = self::buildMeetingEmbed($meeting, false);
        $payload = ['embeds' => [$embed]];

        $mentionRoleIds = $meetingId ? self::mentionRoleIdsForMeeting($meetingId) : [];
        if ($mentionRoleIds) {
            $payload['content'] = implode(' ', array_map(fn($id) => "<@&{$id}>", $mentionRoleIds));
            $payload['allowed_mentions'] = ['parse' => [], 'roles' => $mentionRoleIds];
        }

        $headers = self::botHeaders();
        $channelId = Settings::get('discord_announce_channel_id');
        $canUseBotChannel = $headers && $channelId;
        $needsInteractiveButtons = $meetingId && Settings::get('discord_public_key');
        $useBotForInteractive = $needsInteractiveButtons && $canUseBotChannel;

        $components = [];
        if ($useBotForInteractive) {
            $components[] = self::rsvpActionRow($meetingId);
        }
        if ($meetingId) {
            $components[] = self::linkButtonRow($meetingId);
        }
        if ($components) {
            $payload['components'] = $components;
        }

        if ($useBotForInteractive || $canUseBotChannel) {
            $result = self::request('POST', self::API . "/channels/{$channelId}/messages", json_encode($payload), $headers);
            if (!$result['ok']) return null;
            return ['message_id' => (string) ($result['data']['id'] ?? ''), 'channel_id' => $channelId];
        }

        $webhook = Settings::get('discord_webhook_url');
        if ($webhook) {
            $result = self::request('POST', $webhook . '?wait=true', json_encode($payload), ['Content-Type: application/json']);
            if (!$result['ok']) return null;
            return ['message_id' => (string) ($result['data']['id'] ?? ''), 'channel_id' => null];
        }

        return null;
    }

    /**
     * Bearbeitet eine bereits gepostete Besprechungs-Ankündigung — genutzt beim Absagen, um
     * groß & unübersehbar "ABGESAGT" in den Discord-Kanal zu schreiben, statt die alte
     * Nachricht unverändert stehen zu lassen. Entfernt die Zu-/Absage-Buttons (Antworten
     * ergibt für eine abgesagte Besprechung keinen Sinn mehr), der "Zum Meeting"-Link bleibt.
     * Funktioniert für beide Transportwege (Bot-Kanal anhand von discord_channel_id,
     * Webhook anhand der Edit-Message-Route), je nachdem wie ursprünglich gepostet wurde.
     */
    public static function updateMeetingAnnouncement(array $meeting): bool
    {
        if (empty($meeting['discord_message_id'])) return false;

        $cancelled = ($meeting['status'] ?? '') === 'cancelled';
        $embed = self::buildMeetingEmbed($meeting, $cancelled);
        $payload = ['embeds' => [$embed]];

        $meetingId = (int) ($meeting['id'] ?? 0);
        if ($cancelled && $meetingId) {
            // Zu-/Absage-Buttons entfernen (ergibt für eine abgesagte Besprechung keinen Sinn
            // mehr), nur der immer gültige "Zum Meeting"-Link bleibt übrig.
            $payload['components'] = [self::linkButtonRow($meetingId)];
        }

        if (!empty($meeting['discord_channel_id'])) {
            $headers = self::botHeaders();
            if (!$headers) return false;
            $result = self::request('PATCH', self::API . "/channels/{$meeting['discord_channel_id']}/messages/{$meeting['discord_message_id']}", json_encode($payload), $headers);
            return $result['ok'];
        }

        $webhook = Settings::get('discord_webhook_url');
        if ($webhook) {
            $result = self::request('PATCH', rtrim($webhook, '/') . '/messages/' . $meeting['discord_message_id'], json_encode($payload), ['Content-Type: application/json']);
            return $result['ok'];
        }

        return false;
    }

    private static function buildMeetingEmbed(array $meeting, bool $cancelled): array
    {
        $start = strtotime($meeting['start_time']);

        $fields = [
            ['name' => 'Vom', 'value' => date('d.m.Y H:i', $start) . ' Uhr', 'inline' => false],
        ];
        if (!empty($meeting['end_time'])) {
            $fields[] = ['name' => 'Bis zum', 'value' => date('d.m.Y H:i', strtotime($meeting['end_time'])) . ' Uhr', 'inline' => false];
        }
        if (!empty($meeting['location'])) {
            $fields[] = ['name' => 'Ort', 'value' => $meeting['location'], 'inline' => false];
        }
        $fields[] = ['name' => 'Thema', 'value' => $meeting['title'], 'inline' => false];
        if (!empty($meeting['description'])) {
            $fields[] = ['name' => 'Inhalt', 'value' => mb_substr($meeting['description'], 0, 1000), 'inline' => false];
        }

        if ($cancelled) {
            return [
                'title' => '❌ BESPRECHUNG ABGESAGT',
                'description' => "**Diese Besprechung wurde abgesagt.**\n\n~~Es wurde eine neue Besprechung angesetzt. Wir freuen uns auf zahlreiche Teilnahme.~~",
                'color' => 0xED4245,
                'fields' => $fields,
                'footer' => ['text' => 'Teamverwaltung · Abgesagt'],
                'timestamp' => date('c'),
            ];
        }

        return [
            'title' => '📅 Neue Besprechung',
            'description' => "Es wurde eine neue Besprechung angesetzt. Wir freuen uns auf zahlreiche Teilnahme.\n\n"
                . '*Du kannst deine Teilnahme entweder im Dashboard oder mit den Buttons unten bestätigen oder ablehnen.*',
            'color' => 0x5865F2,
            'fields' => $fields,
            'footer' => ['text' => 'Teamverwaltung'],
            'timestamp' => date('c'),
        ];
    }

    private static function linkButtonRow(int $meetingId): array
    {
        return [
            'type' => 1,
            'components' => [
                ['type' => 2, 'style' => 5, 'label' => 'Zum Meeting', 'url' => Settings::appUrl() . '/meeting_view.php?id=' . $meetingId],
            ],
        ];
    }

    /** Discord-Rollen-IDs der Ränge aller eingeladenen Teilnehmer plus ggf. die Team-Rolle — für die @-Erwähnung in der Ankündigung. */
    private static function mentionRoleIdsForMeeting(int $meetingId): array
    {
        $db = DB::get();
        $ids = [];

        $stmt = $db->prepare("
            SELECT DISTINCT r.discord_role_id
            FROM meeting_attendees ma
            JOIN users u ON u.id = ma.user_id
            JOIN ranks r ON r.id = u.rank_id
            WHERE ma.meeting_id = ? AND r.discord_role_id IS NOT NULL AND r.discord_role_id != ''
        ");
        $stmt->execute([$meetingId]);
        foreach ($stmt->fetchAll() as $row) {
            $ids[] = $row['discord_role_id'];
        }

        $stmt = $db->prepare("
            SELECT t.discord_role_id
            FROM meetings m JOIN teams t ON t.id = m.team_id
            WHERE m.id = ? AND t.discord_role_id IS NOT NULL AND t.discord_role_id != ''
        ");
        $stmt->execute([$meetingId]);
        $teamRole = $stmt->fetchColumn();
        if ($teamRole) $ids[] = $teamRole;

        return array_values(array_unique($ids));
    }

    /**
     * Postet eine neu eingegangene Bewerbung (careers_apply.php) in den optionalen
     * Bewerbungs-Kanal (DISCORD_APPLICATIONS_WEBHOOK_URL), mit Ping auf die Discord-Rollen der
     * Ränge "Teamleitung" und "Stv. Teamleitung" (ranks.discord_role_id — unter Ränge
     * einstellbar). Best-effort: ohne Webhook oder ohne zugeordnete Rollen passiert nichts.
     */
    public static function announceNewApplication(array $application, string $postingTitle): void
    {
        $webhook = Settings::get('discord_applications_webhook_url');
        if (!$webhook) return;

        $stmt = DB::get()->prepare("SELECT discord_role_id FROM ranks WHERE name IN ('Teamleitung', 'Stv. Teamleitung') AND discord_role_id IS NOT NULL AND discord_role_id != ''");
        $stmt->execute();
        $roleIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $fields = [
            ['name' => 'Name', 'value' => $application['applicant_name'], 'inline' => true],
            ['name' => 'Stelle', 'value' => $postingTitle, 'inline' => true],
        ];
        if (!empty($application['applicant_age'])) {
            $fields[] = ['name' => 'Alter', 'value' => (string) $application['applicant_age'], 'inline' => true];
        }
        if (!empty($application['discord_tag'])) {
            $fields[] = ['name' => 'Discord-Tag', 'value' => $application['discord_tag'], 'inline' => true];
        }

        $embed = [
            'title' => '📥 Neue Bewerbung',
            'color' => 0x5865F2,
            'fields' => $fields,
            'footer' => ['text' => 'Teamverwaltung'],
            'timestamp' => date('c'),
        ];

        $payload = [
            'embeds' => [$embed],
            'components' => [[
                'type' => 1,
                'components' => [[
                    'type' => 2, 'style' => 5, 'label' => 'Bewerbung ansehen',
                    'url' => Settings::appUrl() . '/applications.php',
                ]],
            ]],
        ];
        if ($roleIds) {
            $payload['content'] = implode(' ', array_map(fn($id) => "<@&{$id}>", $roleIds));
            $payload['allowed_mentions'] = ['parse' => [], 'roles' => $roleIds];
        }

        self::request('POST', rtrim($webhook, '/') . '?wait=false', json_encode($payload), ['Content-Type: application/json'], 5);
    }

    /**
     * Postet einen unerwarteten technischen Fehler (uncaught Exception, PHP-Fehler/Warning,
     * Fatal Error — siehe die Handler in bootstrap.php) in den optionalen Development-/Fehler-Log-
     * Kanal (DISCORD_DEV_LOG_WEBHOOK_URL). Bewusst getrennt vom normalen Aktivitäts-Log
     * (postLogEvent()): hier geht es um technische Fehler, nicht um Nutzeraktionen. Best-effort
     * mit kurzem Timeout — ein Fehler beim Melden des Fehlers darf die Anwendung nie zusätzlich
     * zum Absturz bringen, daher auch kein Rückgabewert und keine Exceptions nach außen.
     */
    public static function postDevLog(string $title, string $details): void
    {
        try {
            $webhook = Settings::get('discord_dev_log_webhook_url');
            if (!$webhook) return;

            $embed = [
                'title' => '🐛 ' . mb_substr($title, 0, 250),
                'description' => '```' . mb_substr($details, 0, 1900) . '```',
                'color' => 0xED4245,
                'footer' => ['text' => Settings::appUrl()],
                'timestamp' => date('c'),
            ];
            self::request('POST', rtrim($webhook, '/') . '?wait=false', json_encode(['embeds' => [$embed]]), ['Content-Type: application/json'], 4);
        } catch (\Throwable $e) {
            // Absichtlich verschluckt — siehe Docblock oben.
        }
    }

    /**
     * Postet eine protokollierte Aktion (siehe audit_log() in helpers.php) als Embed in den
     * optionalen Aktivitäts-Log-Kanal (DISCORD_LOG_WEBHOOK_URL). Rein informativ und best-effort:
     * ohne konfigurierten Webhook passiert nichts, ein fehlgeschlagener Request blockiert oder
     * verändert nie die eigentliche Aktion (audit_log() schreibt vorher schon in die DB).
     */
    public static function postLogEvent(string $action, string $details, ?string $actorName): void
    {
        $webhook = Settings::get('discord_log_webhook_url');
        if (!$webhook) return;

        $embed = [
            'title' => $action,
            'color' => 0x5865F2,
            'footer' => ['text' => $actorName ? "von {$actorName}" : 'System'],
            'timestamp' => date('c'),
        ];
        if ($details !== '') {
            $embed['description'] = mb_substr($details, 0, 2000);
        }

        // Kurzes Timeout: dieser Aufruf hängt jetzt an praktisch jeder schreibenden Aktion im
        // Dashboard (via audit_log()) — ein langsamer/nicht erreichbarer Webhook darf normale
        // Bedienung nie spürbar verzögern, das Log ist rein informativ.
        self::request('POST', rtrim($webhook, '/') . '?wait=false', json_encode(['embeds' => [$embed]]), ['Content-Type: application/json'], 4);
    }

    private static function rsvpActionRow(int $meetingId): array
    {
        return [
            'type' => 1, // Action Row
            'components' => [
                ['type' => 2, 'style' => 3, 'label' => 'Teilnehmen', 'emoji' => ['name' => '✅'], 'custom_id' => "rsvp:{$meetingId}:accepted"],
                ['type' => 2, 'style' => 2, 'label' => 'Vielleicht', 'emoji' => ['name' => '❔'], 'custom_id' => "rsvp:{$meetingId}:maybe"],
                ['type' => 2, 'style' => 4, 'label' => 'Absagen', 'emoji' => ['name' => '❌'], 'custom_id' => "rsvp:{$meetingId}:declined"],
            ],
        ];
    }

    private static function request(string $method, string $url, $body = null, array $headers = [], int $timeout = 15): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
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
