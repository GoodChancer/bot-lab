<?php
// Moves the studio to a new public address: updates base_url in config.php and, for every bot with a
// Slack app, changes only the app's event URL and install redirect (everything else in Slack stays as is).
// Deploy public/ to the new address first, because Slack checks the new event URL when it's saved.
//   php bin/change-url.php https://example.com/new-path
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Studio\Bots;
use Studio\Config;
use Studio\Slack\Apps;
use Studio\Slack\SlackError;
use Studio\Slack\WebApi;

$new = rtrim((string) ($argv[1] ?? ''), '/');
if (!preg_match('#^https://[^\s]+$#', $new)) {
    exit("Usage: php bin/change-url.php https://example.com/new-path\n");
}
$old = Config::baseUrl();
echo "Moving from $old to $new\n";

// 1. config.php
$path = getenv('STUDIO_CONFIG') ?: STUDIO_ROOT . '/config.php';
$config = require $path;
$config['base_url'] = $new;
file_put_contents($path, "<?php\n// Bot Lab settings. Keep this file private; it holds your keys.\nreturn " . var_export($config, true) . ";\n");
echo "config.php updated\n";

// 2. Each bot's Slack app
$failed = 0;
foreach (Bots::all() as $bot) {
    if ($bot['app_id'] === '') {
        echo "{$bot['slug']}: no Slack app linked, skipped\n";
        continue;
    }
    try {
        $manifest = WebApi::call('apps.manifest.export', ['app_id' => $bot['app_id']], Apps::configToken(), 'form')['manifest'];
        $manifest['settings']['event_subscriptions']['request_url'] = $new . '/events.php?bot=' . rawurlencode($bot['slug']);
        $manifest['oauth_config']['redirect_urls'] = [$new . '/oauth.php'];
        WebApi::call('apps.manifest.update', ['app_id' => $bot['app_id'], 'manifest' => json_encode($manifest)], Apps::configToken(), 'form');
        echo "{$bot['slug']}: Slack app updated\n";
    } catch (SlackError $e) {
        $failed++;
        echo "{$bot['slug']}: FAILED - {$e->getMessage()}\n";
    }
}
echo $failed ? "\n$failed app(s) need their Event Subscriptions Request URL changed by hand at api.slack.com/apps.\n" : "\nDone.\n";
