<?php
// Example only. Run `php bin/setup.php` to create the real config.php (it fills in the password hash
// and encryption key for you). Anything left out falls back to the defaults in src/Config.php.
return [
    'base_url' => 'https://example.com/bots',   // public URL of the public/ folder, no trailing slash
    'password_hash' => '',                       // set by bin/setup.php
    'secret_key' => '',                          // set by bin/setup.php; encrypts stored Slack tokens
    'anthropic_api_key' => '',                   // https://console.anthropic.com
    'openai_api_key' => '',                      // https://platform.openai.com (optional; enables image styles)
    'models' => [
        'chat' => ['anthropic' => 'claude-haiku-5-5', 'openai' => 'gpt-5-mini'],
        'generate' => ['anthropic' => 'claude-opus-5-5', 'openai' => 'gpt-5'],
        'image' => ['openai' => 'gpt-image-2'],
    ],
];
