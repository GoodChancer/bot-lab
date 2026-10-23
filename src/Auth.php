<?php
declare(strict_types=1);

namespace Studio;

/** One owner password (from config.php), a session cookie, and a CSRF token for every change. */
final class Auth
{
    private const MAX_FAILS = 5;
    private const WINDOW = 900; // seconds

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        session_name('botstudio');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function check(): bool
    {
        self::start();
        return !empty($_SESSION['owner']);
    }

    /** Returns an error message, or null on success. */
    public static function login(string $password): ?string
    {
        self::start();
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        Db::query('DELETE FROM login_attempts WHERE at < ?', [time() - self::WINDOW]);
        $fails = (int) Db::query('SELECT COUNT(*) FROM login_attempts WHERE ip = ?', [$ip])->fetchColumn();
        if ($fails >= self::MAX_FAILS) {
            return 'Too many tries. Wait 15 minutes and try again.';
        }
        $hash = (string) Config::get('password_hash');
        if ($hash === '' || !password_verify($password, $hash)) {
            Db::query('INSERT INTO login_attempts (ip, at) VALUES (?, ?)', [$ip, time()]);
            return 'Wrong password.';
        }
        session_regenerate_id(true);
        $_SESSION['owner'] = true;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return null;
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        session_destroy();
    }

    public static function csrfToken(): string
    {
        self::start();
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function checkCsrf(?string $token): bool
    {
        self::start();
        return is_string($token) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
    }
}
