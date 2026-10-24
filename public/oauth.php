<?php
// Slack sends the owner back here after they click Allow on the install screen.
declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Studio\Auth;
use Studio\Log;
use Studio\Slack\Apps;

if (!Auth::isOwner()) {
    http_response_code(403);
    exit('Sign in to the studio first, then start the install again.');
}

$back = './#/';
if (isset($_GET['error'])) {
    header('Location: ' . $back . '?notice=' . rawurlencode('Install cancelled.'));
    exit;
}
try {
    $bot = Apps::finishInstall((string) ($_GET['state'] ?? ''), (string) ($_GET['code'] ?? ''));
    Log::write("{$bot['slug']} installed");
    header('Location: ./#/bot/' . $bot['id'] . '?notice=' . rawurlencode("{$bot['name']} is installed. Say hi in Slack!"));
} catch (Throwable $e) {
    Log::write('install failed: ' . $e->getMessage());
    header('Location: ' . $back . '?notice=' . rawurlencode('Install failed: ' . $e->getMessage()));
}
