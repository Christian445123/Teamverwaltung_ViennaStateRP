<?php

class DB
{
    private static ?PDO $instance = null;

    public static function get(): PDO
    {
        if (self::$instance === null) {
            $dbPath = __DIR__ . '/../storage/app.sqlite';
            self::$instance = new PDO('sqlite:' . $dbPath);
            self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$instance->exec('PRAGMA foreign_keys = ON');
            self::migrate(self::$instance);
        }
        return self::$instance;
    }

    private static function migrate(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS ranks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            level INTEGER NOT NULL DEFAULT 0,
            color TEXT NOT NULL DEFAULT '#5865F2',
            permissions TEXT NOT NULL DEFAULT '[]',
            discord_role_id TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS teams (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            description TEXT,
            discord_role_id TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE,
            password_hash TEXT,
            display_name TEXT NOT NULL,
            email TEXT,
            discord_id TEXT UNIQUE,
            discord_username TEXT,
            discord_avatar TEXT,
            rank_id INTEGER REFERENCES ranks(id) ON DELETE SET NULL,
            team_id INTEGER REFERENCES teams(id) ON DELETE SET NULL,
            is_superadmin INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'active',
            notes TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS meetings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            description TEXT,
            location TEXT,
            start_time TEXT NOT NULL,
            end_time TEXT,
            team_id INTEGER REFERENCES teams(id) ON DELETE SET NULL,
            created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
            discord_event_id TEXT,
            discord_channel_id TEXT,
            discord_message_id TEXT,
            status TEXT NOT NULL DEFAULT 'scheduled',
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS meeting_attendees (
            meeting_id INTEGER NOT NULL REFERENCES meetings(id) ON DELETE CASCADE,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            status TEXT NOT NULL DEFAULT 'pending',
            responded_at TEXT,
            PRIMARY KEY (meeting_id, user_id)
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS audit_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action TEXT NOT NULL,
            details TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        )");

        $count = (int) $db->query("SELECT COUNT(*) c FROM ranks")->fetch()['c'];
        if ($count === 0) {
            $stmt = $db->prepare("INSERT INTO ranks (name, level, color, permissions, discord_role_id) VALUES (?, ?, ?, ?, NULL)");
            $stmt->execute(['Administrator', 100, '#ED4245', json_encode(['members.manage', 'meetings.manage', 'teams.manage', 'ranks.manage', 'discord.manage'])]);
            $stmt->execute(['Teamleiter', 50, '#5865F2', json_encode(['members.manage', 'meetings.manage'])]);
            $stmt->execute(['Mitglied', 10, '#99AAB5', json_encode([])]);
        }
    }
}
