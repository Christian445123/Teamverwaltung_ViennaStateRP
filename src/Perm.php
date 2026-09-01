<?php

defined('APP_BOOTSTRAPPED') || exit('Direct access not permitted.');

class Perm
{
    public static function rankOf(array $user): ?array
    {
        if (empty($user['rank_id'])) return null;
        static $cache = [];
        if (!isset($cache[$user['rank_id']])) {
            $stmt = DB::get()->prepare("SELECT * FROM ranks WHERE id = ?");
            $stmt->execute([$user['rank_id']]);
            $cache[$user['rank_id']] = $stmt->fetch() ?: null;
        }
        return $cache[$user['rank_id']];
    }

    public static function has(array $user, string $permission): bool
    {
        if (!empty($user['is_superadmin'])) return true;
        $rank = self::rankOf($user);
        if (!$rank) return false;
        $perms = json_decode($rank['permissions'] ?? '[]', true) ?: [];
        return in_array($permission, $perms, true);
    }

    public static function all(): array
    {
        return [
            'members.manage' => 'Mitglieder verwalten',
            'meetings.manage' => 'Besprechungen verwalten',
            'meetings.respond' => 'Auf Besprechungen antworten (Zu-/Absagen)',
            'meetings.view_attendance' => 'Zu-/Absagen & Anwesenheit einsehen',
            'meetings.respond_for_others' => 'Für andere Mitglieder zu-/absagen (Stellvertretung)',
            'teams.manage' => 'Teams verwalten',
            'ranks.manage' => 'Ränge & Berechtigungen verwalten',
            'discord.manage' => 'Discord-Einstellungen verwalten',
        ];
    }
}
