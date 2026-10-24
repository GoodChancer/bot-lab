<?php
declare(strict_types=1);

require __DIR__ . '/_boot.php';

use Studio\Auth;
use Studio\Config;

$v = Config::VERSION;
header('Cache-Control: no-store');

try {
    $signedIn = Auth::check();
} catch (Throwable $e) {
    http_response_code(503);
    echo '<!doctype html><meta charset="utf-8"><title>Bot Lab</title><body style="font-family:system-ui;padding:40px">'
        . '<h1>Almost there</h1><p>' . htmlspecialchars($e->getMessage()) . '</p></body>';
    exit;
}
$csrf = $signedIn ? Auth::csrfToken() : '';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="<?= htmlspecialchars($csrf) ?>">
<meta name="robots" content="noindex">
<title>Bot Lab</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Press+Start+2P&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=<?= $v ?>">
<link rel="icon" href="assets/logo.png">
</head>
<body>
<header class="top">
  <a class="brand" href="#/"><img src="assets/logo-mark.png" alt="" height="30"><span>Bot Lab</span></a>
  <?php if ($signedIn): ?>
  <nav><a href="#/">Bots</a><a href="#/settings">Settings</a></nav>
  <?php endif; ?>
</header>
<main id="app">
<?php if (!$signedIn): ?>
  <form class="card login" id="login">
    <h1>Sign in</h1>
    <p class="muted">This studio belongs to one owner. Enter the password you set with <code>php bin/setup.php</code>.</p>
    <input type="password" name="password" placeholder="Password" autocomplete="current-password" required autofocus>
    <button class="btn primary" type="submit">Sign in</button>
    <p class="error" id="login-error"></p>
  </form>
  <?php if (Auth::demoEnabled()): ?>
  <div class="card demo-invite">
    <h2>Just looking?</h2>
    <p class="muted">See everything the owner sees: the bots, the editor, avatars, settings and more. Nothing can be changed, so click around freely.</p>
    <button class="btn" type="button" id="demo-login">View the demo</button>
  </div>
  <?php endif; ?>
<?php endif; ?>
</main>
<div id="toast" role="status"></div>
<script src="assets/cropper.js?v=<?= $v ?>"></script>
<script>window.BOTLAB_VERSION = <?= json_encode($v) ?>;</script>
<script src="assets/app.js?v=<?= $v ?>"></script>
</body>
</html>
