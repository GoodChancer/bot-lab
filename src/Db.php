<?php
declare(strict_types=1);

namespace Studio;

use PDO;

/** One SQLite file in the data folder. Tables are created on first use. */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $dir = (string) Config::get('data_dir');
            if (!is_dir($dir)) {
                mkdir($dir, 0700, true);
            }
            self::$pdo = new PDO('sqlite:' . $dir . '/studio.sqlite', null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$pdo->exec('PRAGMA journal_mode = WAL; PRAGMA busy_timeout = 5000;');
            self::migrate(self::$pdo);
        }
        return self::$pdo;
    }

    /** Tests use an in-memory database. */
    public static function useMemory(): void
    {
        self::$pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        self::migrate(self::$pdo);
    }

    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS bots (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL,
                personality TEXT NOT NULL DEFAULT '',
                short_description TEXT NOT NULL DEFAULT '',
                long_description TEXT NOT NULL DEFAULT '',
                background_color TEXT NOT NULL DEFAULT '#1b2440',
                avatar_description TEXT NOT NULL DEFAULT '',
                avatar_style TEXT NOT NULL DEFAULT 'pixel',
                provider TEXT NOT NULL DEFAULT 'anthropic',
                model TEXT NOT NULL DEFAULT '',
                status TEXT NOT NULL DEFAULT 'draft',
                app_id TEXT NOT NULL DEFAULT '',
                client_id TEXT NOT NULL DEFAULT '',
                client_secret_enc TEXT NOT NULL DEFAULT '',
                signing_secret_enc TEXT NOT NULL DEFAULT '',
                bot_token_enc TEXT NOT NULL DEFAULT '',
                bot_user_id TEXT NOT NULL DEFAULT '',
                team_id TEXT NOT NULL DEFAULT '',
                oauth_state TEXT NOT NULL DEFAULT '',
                icon_synced INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS login_attempts (
                ip TEXT NOT NULL,
                at INTEGER NOT NULL
            );
            SQL);

        // Columns added after the first release.
        $have = array_column($pdo->query('PRAGMA table_info(bots)')->fetchAll(PDO::FETCH_ASSOC), 'name');
        $added = [
            'can_see_images' => 'INTEGER NOT NULL DEFAULT 0',
            'can_read_links' => 'INTEGER NOT NULL DEFAULT 0',
            'can_search_web' => 'INTEGER NOT NULL DEFAULT 0',
            'granted_scopes' => "TEXT NOT NULL DEFAULT ''", // what the owner approved at install; '' = unknown (imported)
            'avatar_source_mime' => "TEXT NOT NULL DEFAULT ''", // original image the avatar was cropped from
            'avatar_crop' => "TEXT NOT NULL DEFAULT ''",        // JSON {x, y, s, even} in source pixels
            'icon_background' => "TEXT NOT NULL DEFAULT ''",    // behind the character in the icon; '' = same as background_color
        ];
        foreach ($added as $col => $type) {
            if (!in_array($col, $have, true)) {
                $pdo->exec("ALTER TABLE bots ADD COLUMN $col $type");
            }
        }
    }
}
