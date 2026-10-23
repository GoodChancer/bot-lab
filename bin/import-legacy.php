<?php
// One-time move of the original three bots into the studio, keeping their Slack URLs (?bot=fox etc.).
//   php bin/import-legacy.php /path/to/old/slackbot-config.php /path/to/characters.json /path/to/icons-dir
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Studio\Bots;
use Studio\Slack\Apps;

[, $configPath, $charactersPath, $iconsDir] = $argv + [null, null, null, null];
if (!$configPath || !$charactersPath) {
    exit("Usage: php bin/import-legacy.php OLD_CONFIG CHARACTERS_JSON [ICONS_DIR]\n");
}
$old = require $configPath;
$characters = json_decode((string) file_get_contents($charactersPath), true)['characters'];
$icons = ['fox' => 'fixel-fox-icon.png', 'chef' => 'doughbert-icon.png', 'duck' => 'judgemental-duck-icon.png'];
$colors = ['fox' => '#2b1d14', 'chef' => '#3c5a5c', 'duck' => '#1d3b33'];

foreach ($old['bots'] as $slug => $secrets) {
    if (Bots::findBySlug($slug)) {
        echo "$slug: already imported\n";
        continue;
    }
    $c = $characters[$slug];
    $bot = Apps::import([
        'slug' => $slug,
        'name' => $c['name'],
        'personality' => $c['prompt'],
        'background_color' => $colors[$slug] ?? '#1b2440',
        'provider' => 'anthropic',
    ], $secrets['bot_token'], $secrets['signing_secret']);
    if ($iconsDir && isset($icons[$slug]) && is_file("$iconsDir/{$icons[$slug]}")) {
        @mkdir(dirname(Bots::avatarPath($slug)), 0700, true);
        copy("$iconsDir/{$icons[$slug]}", Bots::avatarPath($slug));
    }
    echo "$slug: imported as {$bot['name']} (app {$bot['app_id']})\n";
}
