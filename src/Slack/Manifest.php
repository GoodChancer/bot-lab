<?php
declare(strict_types=1);

namespace Studio\Slack;

/** Builds the Slack app manifest for a bot (https://docs.slack.dev/reference/app-manifest). */
final class Manifest
{
    public const BOT_SCOPES = ['app_mentions:read', 'chat:write', 'im:history', 'im:read', 'im:write'];
    public const BOT_EVENTS = ['app_mention', 'message.im'];

    /** Permissions a bot asks for. Seeing images needs files:read to download what people share. */
    public static function scopes(array $bot): array
    {
        $scopes = self::BOT_SCOPES;
        if (!empty($bot['can_see_images'])) {
            $scopes[] = 'files:read';
        }
        sort($scopes);
        return $scopes;
    }

    public static function build(array $bot, string $baseUrl): array
    {
        $display = [
            'name' => $bot['name'],
            'background_color' => strtolower($bot['background_color']),
        ];
        if ($bot['short_description'] !== '') {
            $display['description'] = mb_substr($bot['short_description'], 0, 139);
        }
        // Slack rejects long descriptions under 174 characters, so leave it out until it's long enough.
        $long = $bot['long_description'];
        if (mb_strlen($long) >= 174) {
            $display['long_description'] = mb_substr($long, 0, 4000);
        }

        return [
            'display_information' => $display,
            'features' => [
                'app_home' => [
                    'home_tab_enabled' => false,
                    'messages_tab_enabled' => true,
                    'messages_tab_read_only_enabled' => false,
                ],
                'bot_user' => [
                    'display_name' => mb_substr($bot['name'], 0, 80),
                    'always_online' => true,
                ],
            ],
            'oauth_config' => [
                'redirect_urls' => [$baseUrl . '/oauth.php'],
                'scopes' => ['bot' => self::scopes($bot)],
            ],
            'settings' => [
                'event_subscriptions' => [
                    'request_url' => $baseUrl . '/events.php?bot=' . rawurlencode($bot['slug']),
                    'bot_events' => self::BOT_EVENTS,
                ],
                'org_deploy_enabled' => false,
                'socket_mode_enabled' => false,
                'token_rotation_enabled' => false,
            ],
        ];
    }

    public static function authorizeUrl(array $bot, string $state, string $baseUrl): string
    {
        return 'https://slack.com/oauth/v2/authorize?' . http_build_query([
            'client_id' => $bot['client_id'],
            'scope' => implode(',', self::scopes($bot)),
            'redirect_uri' => $baseUrl . '/oauth.php',
            'state' => $state,
        ]);
    }
}
