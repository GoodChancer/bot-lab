<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

\Studio\Config::override([
    'base_url' => 'https://studio.example.com',
    'secret_key' => base64_encode(str_repeat('k', 32)),
    'data_dir' => sys_get_temp_dir() . '/studio-tests-' . getmypid(),
]);
