<?php
declare(strict_types=1);

// Loaded by every entry point (public/*.php, bin/*.php, tests).
if (!defined('STUDIO_ROOT')) {
    define('STUDIO_ROOT', dirname(__DIR__));
}
require STUDIO_ROOT . '/vendor/autoload.php';

date_default_timezone_set('UTC');
