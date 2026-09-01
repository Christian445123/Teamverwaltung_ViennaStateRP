<?php

class Settings
{
    private static ?array $cache = null;

    private static function loadAll(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            $rows = DB::get()->query("SELECT key, value FROM settings")->fetchAll();
            foreach ($rows as $row) {
                self::$cache[$row['key']] = $row['value'];
            }
        }
        return self::$cache;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $all = self::loadAll();
        return $all[$key] ?? $default;
    }

    public static function set(string $key, ?string $value): void
    {
        $stmt = DB::get()->prepare("INSERT INTO settings (key, value) VALUES (?, ?)
            ON CONFLICT(key) DO UPDATE SET value = excluded.value");
        $stmt->execute([$key, $value]);
        self::$cache[$key] = $value;
    }

    public static function setMany(array $data): void
    {
        foreach ($data as $key => $value) {
            self::set($key, $value);
        }
    }

    public static function appUrl(): string
    {
        $configured = self::get('app_url');
        if ($configured) {
            return rtrim($configured, '/');
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        return $scheme . '://' . $host . $dir;
    }

    public static function isDiscordConfigured(): bool
    {
        return (bool) self::get('discord_client_id') && (bool) self::get('discord_client_secret');
    }

    public static function isBotConfigured(): bool
    {
        return (bool) self::get('discord_bot_token') && (bool) self::get('discord_guild_id');
    }
}
