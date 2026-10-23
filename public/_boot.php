<?php
declare(strict_types=1);

// Finds the app code. Normally it's one folder up; when public/ is deployed somewhere else
// (like a shared host's public_html), deploy writes app-root.php with the real path.
$root = is_file(__DIR__ . '/app-root.php') ? require __DIR__ . '/app-root.php' : dirname(__DIR__);
define('STUDIO_ROOT', $root);
require $root . '/src/bootstrap.php';
