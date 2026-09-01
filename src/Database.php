<?php

defined('APP_BOOTSTRAPPED') || exit('Direct access not permitted.');

class DB
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance === null) {
            self::$instance = self::connect();
            self::migrate(self::$instance);
        }
        return self::$instance;
    }

    private static function connect(): PDO
    {
        Env::load();

        $host = Env::get('DB_HOST', '127.0.0.1');
        $port = Env::get('DB_PORT', '3306');
        $name = Env::get('DB_NAME', 'teamverwaltung');
        $user = Env::get('DB_USER', '');
        $pass = Env::get('DB_PASS', '');
        $charset = Env::get('DB_CHARSET', 'utf8mb4');

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $name, $charset);

        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10,
        ]);
        $pdo->exec("SET NAMES {$charset} COLLATE utf8mb4_unicode_ci");

        return $pdo;
    }

    private static function migrate(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS ranks (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            level INT NOT NULL DEFAULT 0,
            color VARCHAR(7) NOT NULL DEFAULT '#5865F2',
            permissions TEXT NOT NULL,
            discord_role_id VARCHAR(32) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS teams (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(150) NOT NULL,
            description TEXT NULL,
            discord_role_id VARCHAR(32) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            username VARCHAR(100) NULL,
            password_hash VARCHAR(255) NULL,
            display_name VARCHAR(150) NOT NULL,
            email VARCHAR(190) NULL,
            discord_id VARCHAR(32) NULL,
            discord_username VARCHAR(100) NULL,
            discord_avatar VARCHAR(100) NULL,
            rank_id INT UNSIGNED NULL,
            team_id INT UNSIGNED NULL,
            is_superadmin TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            notes TEXT NULL,
            is_high_team TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_username (username),
            UNIQUE KEY uniq_discord_id (discord_id),
            KEY idx_rank (rank_id),
            KEY idx_team (team_id),
            CONSTRAINT fk_users_rank FOREIGN KEY (rank_id) REFERENCES ranks(id) ON DELETE SET NULL,
            CONSTRAINT fk_users_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // is_high_team wurde nachträglich ergänzt — auf bereits bestehenden Installationen
        // per ALTER nachziehen (CREATE TABLE IF NOT EXISTS greift dort nicht mehr).
        $existingUserCols = $db->query("SHOW COLUMNS FROM `users`")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('is_high_team', $existingUserCols, true)) {
            $db->exec("ALTER TABLE `users` ADD COLUMN `is_high_team` TINYINT(1) NOT NULL DEFAULT 0");
        }

        $db->exec("CREATE TABLE IF NOT EXISTS discord_extra_roles (
            slug VARCHAR(20) NOT NULL,
            label VARCHAR(50) NOT NULL,
            discord_role_id VARCHAR(32) NULL,
            auto_assign TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $extraRolesCount = (int) $db->query("SELECT COUNT(*) c FROM discord_extra_roles")->fetch()['c'];
        if ($extraRolesCount === 0) {
            $stmt = $db->prepare("INSERT INTO discord_extra_roles (slug, label, discord_role_id, auto_assign) VALUES (?, ?, NULL, ?)");
            // "Team": allgemeine Teamgruppe – wird jedem aktiven, verknüpften Mitglied automatisch zusätzlich zu seinem Rang gesetzt.
            $stmt->execute(['team', 'Team', 1]);
            // "High-Team": wird NIE automatisch vergeben/entfernt (nur manuell in Discord gepflegt),
            // aber der aktuelle Status wird beim Rollen-Sync gelesen und in users.is_high_team gespiegelt.
            $stmt->execute(['high_team', 'High-Team', 0]);
        }

        $db->exec("CREATE TABLE IF NOT EXISTS meetings (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(200) NOT NULL,
            description TEXT NULL,
            location VARCHAR(255) NULL,
            start_time DATETIME NOT NULL,
            end_time DATETIME NULL,
            team_id INT UNSIGNED NULL,
            created_by INT UNSIGNED NULL,
            discord_event_id VARCHAR(32) NULL,
            discord_channel_id VARCHAR(32) NULL,
            discord_message_id VARCHAR(32) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_start (start_time),
            KEY idx_meetings_team (team_id),
            CONSTRAINT fk_meetings_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL,
            CONSTRAINT fk_meetings_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS meeting_attendees (
            meeting_id INT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            responded_at DATETIME NULL,
            PRIMARY KEY (meeting_id, user_id),
            KEY idx_ma_user (user_id),
            CONSTRAINT fk_ma_meeting FOREIGN KEY (meeting_id) REFERENCES meetings(id) ON DELETE CASCADE,
            CONSTRAINT fk_ma_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->exec("CREATE TABLE IF NOT EXISTS audit_log (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id INT UNSIGNED NULL,
            action VARCHAR(100) NOT NULL,
            details TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Ränge exakt wie die Discord-Rollen benennen (Namen/Reihenfolge/Farben angelehnt an
        // die Rollen-Hierarchie von ViennaStateRP/Website: includes/permissions.php).
        // Namensbasiert & idempotent: fügt nur fehlende Ränge hinzu, rührt bestehende (inkl.
        // bereits zugewiesener discord_role_id oder Mitglieder) nicht an.
        $wantedRanks = [
            ['Test-Supporter',      10, '#4ade80', []],
            ['Supporter',           20, '#22c55e', []],
            ['Moderator',           30, '#7c3aed', []],
            ['Test-Developer',      40, '#06b6d4', []],
            ['Developer',           50, '#0ea5e9', []],
            ['Test-Admin',          60, '#f97316', ['meetings.manage']],
            ['Admin',               70, '#ef4444', ['members.manage', 'meetings.manage']],
            ['Stv. Teamleitung',    80, '#e879f9', ['members.manage', 'meetings.manage', 'teams.manage']],
            ['Teamleitung',         90, '#d946ef', ['members.manage', 'meetings.manage', 'teams.manage']],
            ['Stv. Projektleitung', 100, '#fb923c', ['members.manage', 'meetings.manage', 'teams.manage', 'ranks.manage']],
            ['Projektleitung',      110, '#f59e0b', ['members.manage', 'meetings.manage', 'teams.manage', 'ranks.manage', 'discord.manage']],
            ['Owner',               120, '#e8b86d', ['members.manage', 'meetings.manage', 'teams.manage', 'ranks.manage', 'discord.manage']],
        ];

        $existingNames = $db->query("SELECT name FROM ranks")->fetchAll(PDO::FETCH_COLUMN);
        $insertRank = $db->prepare("INSERT INTO ranks (name, level, color, permissions, discord_role_id) VALUES (?, ?, ?, ?, NULL)");
        foreach ($wantedRanks as [$name, $level, $color, $perms]) {
            if (!in_array($name, $existingNames, true)) {
                $insertRank->execute([$name, $level, $color, json_encode($perms)]);
            }
        }

        // Alte Platzhalter-Ränge aus der ersten Version aufräumen, aber nur, wenn ihnen
        // niemand (mehr) zugewiesen ist — bestehende Zuordnungen werden nie angefasst.
        $legacyNames = ['Administrator', 'Teamleiter', 'Mitglied'];
        foreach ($legacyNames as $legacyName) {
            $stmt = $db->prepare("SELECT id FROM ranks WHERE name = ?");
            $stmt->execute([$legacyName]);
            $legacyId = $stmt->fetchColumn();
            if ($legacyId === false) continue;
            $countStmt = $db->prepare("SELECT COUNT(*) c FROM users WHERE rank_id = ?");
            $countStmt->execute([$legacyId]);
            if ((int) $countStmt->fetch()['c'] === 0) {
                $db->prepare("DELETE FROM ranks WHERE id = ?")->execute([$legacyId]);
            }
        }
    }
}
