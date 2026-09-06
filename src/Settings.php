<?php

defined('APP_BOOTSTRAPPED') || exit('Direct access not permitted.');

/**
 * Kern-Zugangsdaten (Client-Secret, Bot-Token, Public Key, App-URL) kommen ausschließlich aus
 * der .env-Datei — nicht aus der Datenbank. Ausnahme: die in DB_OVERRIDABLE gelisteten Webhooks
 * und die Ankündigungs-Channel-ID lassen sich zusätzlich per UI (Discord & Einstellungen)
 * pflegen (app_settings-Tabelle) — praktischer als nach jeder Änderung die .env auf jedem
 * Server manuell anzufassen. Ein per UI gesetzter Wert hat Vorrang vor der .env; ein leeres
 * Feld löscht den Override und fällt zurück auf die .env. Rollen-Zuordnungen pro Rang/Team
 * bleiben ohnehin in der DB (ranks.discord_role_id / teams.discord_role_id), da sie fachliche
 * Daten sind, keine Zugangsdaten.
 */
class Settings
{
    private const MAP = [
        'app_url' => 'APP_URL',
        'site_name' => 'SITE_NAME',
        'discord_client_id' => 'DISCORD_CLIENT_ID',
        'discord_client_secret' => 'DISCORD_CLIENT_SECRET',
        'discord_redirect_uri' => 'DISCORD_REDIRECT_URI',
        'discord_public_key' => 'DISCORD_PUBLIC_KEY',
        'discord_bot_token' => 'DISCORD_BOT_TOKEN',
        'discord_guild_id' => 'DISCORD_GUILD_ID',
        'discord_webhook_url' => 'DISCORD_WEBHOOK_URL',
        'discord_announce_channel_id' => 'DISCORD_ANNOUNCE_CHANNEL_ID',
        'discord_log_webhook_url' => 'DISCORD_LOG_WEBHOOK_URL',
        'discord_applications_webhook_url' => 'DISCORD_APPLICATIONS_WEBHOOK_URL',
        'discord_dev_log_webhook_url' => 'DISCORD_DEV_LOG_WEBHOOK_URL',
    ];

    /** Einzige Schlüssel, die per UI (statt nur .env) gesetzt werden dürfen — siehe Docblock oben. */
    public const DB_OVERRIDABLE = [
        'discord_webhook_url',
        'discord_announce_channel_id',
        'discord_log_webhook_url',
        'discord_applications_webhook_url',
        'discord_dev_log_webhook_url',
    ];

    public static function get(string $key, ?string $default = null): ?string
    {
        Env::load();

        if (in_array($key, self::DB_OVERRIDABLE, true)) {
            $dbValue = self::getFromDb($key);
            if ($dbValue !== null && $dbValue !== '') {
                return $dbValue;
            }
        }

        $envKey = self::MAP[$key] ?? strtoupper($key);
        $value = Env::get($envKey, '');
        return $value !== '' ? $value : $default;
    }

    /**
     * Setzt einen per-UI-überschreibbaren Wert (siehe DB_OVERRIDABLE). Nicht-leere Werte werden
     * wie in der .env optional mit "ENC:"-Präfix verschlüsselt gespeichert. Ein leerer Wert
     * löscht den Override komplett (Rückfall auf die .env), statt eine leere Zeile zu speichern.
     */
    public static function set(string $key, string $value): void
    {
        if (!in_array($key, self::DB_OVERRIDABLE, true)) {
            throw new InvalidArgumentException("'{$key}' ist nicht per UI überschreibbar.");
        }
        $value = trim($value);
        if ($value === '') {
            DB::get()->prepare("DELETE FROM app_settings WHERE `key` = ?")->execute([$key]);
            return;
        }
        $stored = Env::encrypt($value);
        DB::get()->prepare("INSERT INTO app_settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")
            ->execute([$key, $stored]);
    }

    /** Ob ein Wert aktuell per DB-Override gesetzt ist (für die UI: "überschreibt .env"-Hinweis). */
    public static function hasDbOverride(string $key): bool
    {
        if (!in_array($key, self::DB_OVERRIDABLE, true)) return false;
        return self::getFromDb($key) !== null;
    }

    private static function getFromDb(string $key): ?string
    {
        try {
            $stmt = DB::get()->prepare("SELECT value FROM app_settings WHERE `key` = ?");
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            if ($value === false || $value === null || $value === '') return null;
            return Env::decryptIfNeeded($value);
        } catch (\Throwable $e) {
            return null; // z. B. app_settings existiert noch nicht (Migration lief noch nicht) — sicher auf .env zurückfallen
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
