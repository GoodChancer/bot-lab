<?php
// Creates config.php: the studio's address, owner password, encryption key and AI keys.
//   php bin/setup.php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Studio\Crypto;

$path = STUDIO_ROOT . '/config.php';
$existing = is_file($path) ? require $path : [];

function ask(string $question, string $default = '', bool $secret = false): string
{
    $hint = $default !== '' ? ($secret ? ' [keep current]' : " [$default]") : '';
    echo "$question$hint: ";
    if ($secret && DIRECTORY_SEPARATOR === '/') {
        system('stty -echo');
    }
    $answer = trim((string) fgets(STDIN));
    if ($secret && DIRECTORY_SEPARATOR === '/') {
        system('stty echo');
        echo "\n";
    }
    return $answer !== '' ? $answer : $default;
}

echo "Bot Lab setup\n\n";
$baseUrl = rtrim(ask('Public URL of the public/ folder (https://...)', (string) ($existing['base_url'] ?? '')), '/');
if (!str_starts_with($baseUrl, 'https://') && !str_starts_with($baseUrl, 'http://localhost')) {
    echo "Slack needs an https:// address to send messages to your bots.\n";
}

$password = ask('Owner password for the studio (8+ characters)', '', true);
$hash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : (string) ($existing['password_hash'] ?? '');
if ($hash === '' || ($password !== '' && strlen($password) < 8)) {
    exit("A password of at least 8 characters is required.\n");
}

$anthropic = ask('Anthropic API key (sk-ant-..., blank to skip)', (string) ($existing['anthropic_api_key'] ?? ''), true);
$openai = ask('OpenAI API key (sk-..., blank to skip)', (string) ($existing['openai_api_key'] ?? ''), true);
if ($anthropic === '' && $openai === '') {
    exit("Add at least one AI provider key.\n");
}

$config = array_replace($existing, [
    'base_url' => $baseUrl,
    'password_hash' => $hash,
    'secret_key' => $existing['secret_key'] ?? Crypto::newKey(),
    'anthropic_api_key' => $anthropic,
    'openai_api_key' => $openai,
]);

$php = "<?php\n// Bot Lab settings. Keep this file private; it holds your keys.\n// Change models or other defaults here too (see src/Config.php).\nreturn " . var_export($config, true) . ";\n";
$old = umask(0077);
file_put_contents($path, $php);
umask($old);
chmod($path, 0600);
echo "\nSaved config.php. Open {$baseUrl}/ and sign in.\n";
