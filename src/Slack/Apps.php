<?php
declare(strict_types=1);

namespace Studio\Slack;

use Studio\Bots;
use Studio\Config;
use Studio\Settings;

/** Creates, installs, updates and deletes Slack apps for bots. */
final class Apps
{
    // ---- App configuration token (expires every 12 hours; we keep it fresh) -----------------

    /** Saves the pair from api.slack.com/apps → "Your App Configuration Tokens", checking it works. */
    public static function saveConfigTokens(string $refreshToken): void
    {
        self::rotate(trim($refreshToken));
    }

    public static function hasConfigToken(): bool
    {
        return Settings::get('slack_config_refresh') !== '';
    }

    public static function configToken(): string
    {
        if (!self::hasConfigToken()) {
            throw new \RuntimeException('Add a Slack app configuration token in Settings first.');
        }
        if ((int) Settings::get('slack_config_exp') - 300 < time()) {
            self::rotate(Settings::getSecret('slack_config_refresh'));
        }
        return Settings::getSecret('slack_config_token');
    }

    private static function rotate(string $refreshToken): void
    {
        $r = WebApi::call('tooling.tokens.rotate', ['refresh_token' => $refreshToken], null, 'form');
        Settings::setSecret('slack_config_token', (string) $r['token']);
        Settings::setSecret('slack_config_refresh', (string) $r['refresh_token']);
        Settings::set('slack_config_exp', (string) (int) $r['exp']);
        Settings::set('slack_team_id', (string) ($r['team_id'] ?? ''));
    }

    // ---- Lifecycle --------------------------------------------------------------------------

    /** Creates the Slack app. Returns a message about the icon if it couldn't be set. */
    public static function create(array $bot): ?string
    {
        if ($bot['app_id'] !== '') {
            throw new \RuntimeException('This bot already has a Slack app.');
        }
        $r = WebApi::call('apps.manifest.create', [
            'manifest' => json_encode(Manifest::build($bot, Config::baseUrl())),
        ], self::configToken(), 'form');

        $id = (int) $bot['id'];
        Bots::setSystem($id, ['app_id' => $r['app_id'], 'client_id' => $r['credentials']['client_id'], 'status' => 'created']);
        Bots::setSecret($id, 'client_secret', (string) $r['credentials']['client_secret']);
        Bots::setSecret($id, 'signing_secret', (string) $r['credentials']['signing_secret']);

        return self::setIcon(Bots::find($id));
    }

    /** Pushes name, descriptions and color changes to Slack. */
    public static function update(array $bot): ?string
    {
        WebApi::call('apps.manifest.update', [
            'app_id' => $bot['app_id'],
            'manifest' => json_encode(Manifest::build($bot, Config::baseUrl())),
        ], self::configToken(), 'form');
        return $bot['icon_synced'] ? null : self::setIcon($bot);
    }

    /** Returns null on success, or a message saying to upload the icon by hand. */
    public static function setIcon(array $bot): ?string
    {
        $path = Bots::avatarPath($bot['slug']);
        if ($bot['app_id'] === '' || !is_file($path)) {
            return null;
        }
        try {
            WebApi::call('apps.icon.set', [
                'app_id' => $bot['app_id'],
                'file' => new \CURLFile($path, 'image/png', 'icon.png'),
            ], self::configToken(), 'multipart');
            Bots::setSystem((int) $bot['id'], ['icon_synced' => 1]);
            return null;
        } catch (SlackError $e) {
            Bots::setSystem((int) $bot['id'], ['icon_synced' => 0]);
            return "Slack didn't accept the icon automatically ({$e->error}). Download it and upload it on the app's Basic Information page.";
        }
    }

    public static function startInstall(array $bot): string
    {
        $state = bin2hex(random_bytes(16));
        Bots::setSystem((int) $bot['id'], ['oauth_state' => $state]);
        return Manifest::authorizeUrl($bot, $state, Config::baseUrl());
    }

    /** Called from oauth.php after the owner clicks Allow. */
    public static function finishInstall(string $state, string $code): array
    {
        $bot = Bots::findByState($state) ?? throw new \RuntimeException('This install link has expired. Start the install again from the studio.');
        $r = WebApi::call('oauth.v2.access', [
            'client_id' => $bot['client_id'],
            'client_secret' => Bots::secret($bot, 'client_secret'),
            'code' => $code,
            'redirect_uri' => Config::baseUrl() . '/oauth.php',
        ], null, 'form');

        $id = (int) $bot['id'];
        Bots::setSecret($id, 'bot_token', (string) $r['access_token']);
        Bots::setSystem($id, [
            'bot_user_id' => (string) ($r['bot_user_id'] ?? ''),
            'team_id' => (string) ($r['team']['id'] ?? ''),
            'granted_scopes' => (string) ($r['scope'] ?? ''),
            'status' => 'installed',
            'oauth_state' => '',
        ]);
        return Bots::find($id);
    }

    public static function delete(array $bot): void
    {
        if ($bot['app_id'] !== '' && self::hasConfigToken()) {
            try {
                WebApi::call('apps.manifest.delete', ['app_id' => $bot['app_id']], self::configToken(), 'form');
            } catch (SlackError $e) {
                // Apps created outside the studio (imported) can't be deleted with this token; remove ours anyway.
                if (!in_array($e->error, ['app_not_found', 'invalid_app_id', 'not_allowed', 'no_permission'], true)) {
                    throw $e;
                }
            }
        }
        Bots::delete((int) $bot['id']);
    }

    /** Asks Slack which permissions the bot's token has, and remembers them. Returns the updated bot. */
    public static function checkScopes(array $bot): array
    {
        $token = Bots::secret($bot, 'bot_token');
        if ($token !== '') {
            Bots::setSystem((int) $bot['id'], ['granted_scopes' => WebApi::grantedScopes($token)]);
        }
        return Bots::find((int) $bot['id']);
    }

    /** Where to reinstall an app in Slack (for apps the studio didn't create, so it can't run the install itself). */
    public static function slackInstallPage(array $bot): string
    {
        return $bot['app_id'] !== '' ? 'https://api.slack.com/apps/' . rawurlencode($bot['app_id']) . '/install-on-team' : 'https://api.slack.com/apps';
    }

    /** Adds a bot whose Slack app already exists (made by hand). */
    public static function import(array $fields, string $botToken, string $signingSecret): array
    {
        $auth = WebApi::call('auth.test', [], $botToken, 'form');
        $appId = '';
        if (!empty($auth['bot_id'])) {
            try {
                $appId = (string) (WebApi::call('bots.info', ['bot' => $auth['bot_id']], $botToken, 'get')['bot']['app_id'] ?? '');
            } catch (SlackError) {
            }
        }
        $bot = Bots::create($fields);
        $id = (int) $bot['id'];
        Bots::setSecret($id, 'bot_token', $botToken);
        Bots::setSecret($id, 'signing_secret', $signingSecret);
        Bots::setSystem($id, [
            'bot_user_id' => (string) ($auth['user_id'] ?? ''),
            'team_id' => (string) ($auth['team_id'] ?? ''),
            'app_id' => $appId,
            'status' => 'installed',
            'icon_synced' => 1,
        ]);
        try {
            return self::checkScopes(Bots::find($id));
        } catch (SlackError) {
            return Bots::find($id);
        }
    }
}
