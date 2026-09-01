<?php

/**
 * Alle wichtigen/geheimen Einstellungen (Discord-Zugangsdaten, App-URL) kommen
 * ausschließlich aus der .env-Datei — nicht aus der Datenbank. Rollen-Zuordnungen
 * pro Rang/Team bleiben in der DB (ranks.discord_role_id / teams.discord_role_id),
 * da sie fachliche Daten sind, keine Zugangsdaten.
 */
class Settings
{
    private const MAP = [
        'app_url' => 'APP_URL',
        'site_name' => 'SITE_NAME',
        'discord_client_id' => 'DISCORD_CLIENT_ID',
        'discord_client_secret' => 'DISCORD_CLIENT_SECRET',
        'discord_redirect_uri' => 'DISCORD_REDIRECT_URI',
        'discord_bot_token' => 'DISCORD_BOT_TOKEN',
        'discord_guild_id' => 'DISCORD_GUILD_ID',
        'discord_webhook_url' => 'DISCORD_WEBHOOK_URL',
        'discord_announce_channel_id' => 'DISCORD_ANNOUNCE_CHANNEL_ID',
    ];

    public static function get(string $key, ?string $default = null): ?string
    {
        Env::load();
        $envKey = self::MAP[$key] ?? strtoupper($key);
        $value = Env::get($envKey, '');
        return $value !== '' ? $value : $default;
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
