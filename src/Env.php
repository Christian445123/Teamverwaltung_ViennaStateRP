<?php

defined('APP_BOOTSTRAPPED') || exit('Direct access not permitted.');

/**
 * .env-Loader mit Unterstützung für verschlüsselte Werte (Präfix "ENC:").
 *
 * Verschlüsselung: AES-256-CBC, Schlüssel = SHA-256(Secret), pro Wert ein
 * zufälliger 16-Byte-IV, gespeichert als "ENC:" + base64(iv . ciphertext).
 * Der Secret kommt entweder aus einer Key-Datei außerhalb des Document Roots
 * (empfohlen, Priorität 1) oder aus APP_SECRET_KEY in der .env selbst
 * (Priorität 2, muss dort unverschlüsselt stehen).
 */
class Env
{
    private static bool $loaded = false;

    public static function load(): void
    {
        if (self::$loaded) return;
        self::$loaded = true;

        $raw = self::parseFile();
        if ($raw === null) return;

        $secretKey = self::resolveSecretKey($raw);

        foreach ($raw as $key => $value) {
            if ($secretKey !== null && str_starts_with($value, 'ENC:')) {
                $value = self::decrypt($value, $secretKey);
            }
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }
    }

    public static function get(string $key, string $default = ''): string
    {
        $value = $_ENV[$key] ?? getenv($key);
        return ($value !== false && $value !== null && $value !== '') ? (string) $value : $default;
    }

    /** Verschlüsselt einen Klartext-Wert für die .env. Wird von encrypt_env.php (CLI) genutzt. */
    public static function encrypt(string $plain): string
    {
        $raw = self::parseFile() ?? [];
        $secretKey = self::resolveSecretKey($raw);
        if ($secretKey === null) {
            throw new RuntimeException('Kein Verschlüsselungs-Key gefunden (APP_SECRET_KEY in .env oder Key-Datei fehlt).');
        }

        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($plain, 'aes-256-cbc', $secretKey, OPENSSL_RAW_DATA, $iv);
        return 'ENC:' . base64_encode($iv . $ciphertext);
    }

    /**
     * Entschlüsselt einen Wert mit "ENC:"-Präfix mit demselben Key wie die .env selbst (Key-Datei
     * oder APP_SECRET_KEY) — für in der Datenbank gespeicherte Werte (siehe Settings::set()), die
     * denselben Verschlüsselungsmechanismus wiederverwenden, statt einen zweiten zu erfinden.
     * Werte ohne "ENC:"-Präfix (Klartext) werden unverändert zurückgegeben.
     */
    public static function decryptIfNeeded(string $value): string
    {
        if (!str_starts_with($value, 'ENC:')) return $value;
        $secretKey = self::resolveSecretKey(self::parseFile() ?? []);
        if ($secretKey === null) return $value;
        return self::decrypt($value, $secretKey);
    }

    private static function parseFile(): ?array
    {
        $envFile = __DIR__ . '/../.env';
        if (!is_readable($envFile)) return null;

        $raw = [];
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
                $value = substr($value, 1, -1);
            }
            $raw[$key] = $value;
        }
        return $raw;
    }

    private static function resolveSecretKey(array $raw): ?string
    {
        $keyFile = getenv('APP_KEY_FILE') ?: ($raw['APP_KEY_FILE'] ?? '');
        $keyFile = $keyFile ?: (dirname($_SERVER['DOCUMENT_ROOT'] ?? __DIR__) . '/teamverwaltung.key');
        if ($keyFile !== '' && is_readable($keyFile)) {
            $fileKey = trim((string) file_get_contents($keyFile));
            if ($fileKey !== '') return hash('sha256', $fileKey, true);
        }

        $envKey = $raw['APP_SECRET_KEY'] ?? '';
        if ($envKey !== '') return hash('sha256', $envKey, true);

        return null;
    }

    private static function decrypt(string $value, string $binaryKey): string
    {
        if (!function_exists('openssl_decrypt')) return $value;

        $raw = base64_decode(substr($value, 4), true);
        if ($raw === false || strlen($raw) <= 16) return $value;

        $iv = substr($raw, 0, 16);
        $ciphertext = substr($raw, 16);
        $plain = openssl_decrypt($ciphertext, 'aes-256-cbc', $binaryKey, OPENSSL_RAW_DATA, $iv);

        return $plain === false ? $value : $plain;
    }
}
