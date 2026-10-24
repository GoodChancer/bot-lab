<?php
declare(strict_types=1);

namespace Studio;

/**
 * Who's signed in: the owner (password from config.php) or, when demo_mode is on, a demo visitor
 * who can look at everything but change nothing. Plus a CSRF token for every change.
 *
 * Several Bot Lab copies can share one website (say /botlab and /botlab-demo), so each copy gets its
 * own session cookie, limited to its own folder, and keeps its sign-in under its own key.
 */
final class Auth
{
    private const MAX_FAILS = 5;
    private const WINDOW = 900; // seconds

    /** This copy of Bot Lab (its code folder), so one copy's sign-in never counts in another. */
    private static function instance(): string
    {
        return substr(hash('sha256', STUDIO_ROOT . '|' . Config::baseUrl()), 0, 12);
    }

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $path = rtrim((string) (parse_url(Config::baseUrl(), PHP_URL_PATH) ?? ''), '/') . '/';
        session_name('botlab_' . self::instance());
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $path,
            'secure' => ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /** Our part of the session. */
    private static function &session(): array
    {
        self::start();
        $key = 'botlab_' . self::instance();
        if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
            $_SESSION[$key] = [];
        }
        return $_SESSION[$key];
    }

    /** Signed in as the owner or a demo visitor. */
    public static function check(): bool
    {
        return self::isOwner() || self::isDemo();
    }

    public static function isOwner(): bool
    {
        return !empty(self::session()['owner']);
    }

    public static function isDemo(): bool
    {
        return !empty(self::session()['demo']) && self::demoEnabled();
    }

    public static function demoEnabled(): bool
    {
        return (bool) Config::get('demo_mode', false);
    }

    /** Returns an error message, or null on success. */
    public static function login(string $password): ?string
    {
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
        self::begin(['owner' => true]);
        return null;
    }

    /** Look around without a password. Only when demo_mode is on; nothing can be changed. */
    public static function loginDemo(): ?string
    {
        if (!self::demoEnabled()) {
            return 'The demo is turned off.';
        }
        self::begin(['demo' => true]);
        return null;
    }

    private static function begin(array $who): void
    {
        self::start();
        session_regenerate_id(true);
        $s = &self::session();
        $s = $who + ['csrf' => bin2hex(random_bytes(32))];
    }

    public static function logout(): void
    {
        $s = &self::session();
        $s = [];
    }

    public static function csrfToken(): string
    {
        $s = &self::session();
        return $s['csrf'] ??= bin2hex(random_bytes(32));
    }

    public static function checkCsrf(?string $token): bool
    {
        $s = self::session();
        return is_string($token) && isset($s['csrf']) && hash_equals($s['csrf'], $token);
    }
}
