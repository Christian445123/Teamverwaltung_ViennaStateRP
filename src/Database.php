<?php

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

        $count = (int) $db->query("SELECT COUNT(*) c FROM ranks")->fetch()['c'];
        if ($count === 0) {
            $stmt = $db->prepare("INSERT INTO ranks (name, level, color, permissions, discord_role_id) VALUES (?, ?, ?, ?, NULL)");
            $stmt->execute(['Administrator', 100, '#ED4245', json_encode(['members.manage', 'meetings.manage', 'teams.manage', 'ranks.manage', 'discord.manage'])]);
            $stmt->execute(['Teamleiter', 50, '#5865F2', json_encode(['members.manage', 'meetings.manage'])]);
            $stmt->execute(['Mitglied', 10, '#99AAB5', json_encode([])]);
        }
    }
}
