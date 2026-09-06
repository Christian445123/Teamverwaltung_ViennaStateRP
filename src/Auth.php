<?php

defined('APP_BOOTSTRAPPED') || exit('Direct access not permitted.');

class Auth
{
    private static ?array $userCache = null;
    private static bool $loaded = false;

    public static function currentUser(): ?array
    {
        if (self::$loaded) {
            return self::$userCache;
        }
        self::$loaded = true;
        self::$userCache = null;

        if (!empty($_SESSION['user_id'])) {
            // is_banned = 0: eine laufende Session bricht sofort ab, sobald jemand gesperrt wird
            // (nicht erst beim nächsten Login) — Sperren soll sofort wirken.
            $stmt = DB::get()->prepare("SELECT * FROM users WHERE id = ? AND status = 'active' AND is_banned = 0");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            if ($user) {
                self::$userCache = $user;
            } else {
                unset($_SESSION['user_id']);
            }
        }
        return self::$userCache;
    }

    public static function attemptLogin(string $username, string $password): ?array
    {
        $stmt = DB::get()->prepare("SELECT * FROM users WHERE username = ? AND status = 'active' AND is_banned = 0");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if ($user && $user['password_hash'] && password_verify($password, $user['password_hash'])) {
            return $user;
        }
        return null;
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        self::$loaded = false;
        self::$userCache = null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    public static function requireLogin(): array
    {
        $user = self::currentUser();
        if (!$user) {
            flash('error', 'Bitte melde dich an, um fortzufahren.');
            redirect(url('login.php'));
        }
        return $user;
    }

    public static function requirePermission(string $permission): array
    {
        $user = self::requireLogin();
        if (!Perm::has($user, $permission)) {
            flash('error', 'Dir fehlt die Berechtigung für diese Aktion.');
            redirect(url('index.php'));
        }
        return $user;
    }

    public static function hasAnyUsers(): bool
    {
        $count = (int) DB::get()->query("SELECT COUNT(*) c FROM users")->fetch()['c'];
        return $count > 0;
    }
}
