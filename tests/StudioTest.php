<?php
declare(strict_types=1);

namespace Studio\Tests;

use PHPUnit\Framework\TestCase;
use Studio\Ai\Generator;
use Studio\Bots;
use Studio\Crypto;
use Studio\Db;
use Studio\Slack\Manifest;

final class StudioTest extends TestCase
{
    protected function setUp(): void
    {
        Db::useMemory();
    }

    public function testCryptoRoundTrip(): void
    {
        $enc = Crypto::encrypt('xoxb-secret');
        $this->assertNotSame('xoxb-secret', $enc);
        $this->assertSame('xoxb-secret', Crypto::decrypt($enc));
        $this->assertNotSame($enc, Crypto::encrypt('xoxb-secret'), 'each encryption uses a fresh nonce');
    }

    public function testBotsStoreSecretsEncryptedAndHideThem(): void
    {
        $bot = Bots::create(['name' => 'Fixel Fox', 'personality' => 'You fix things.']);
        $this->assertSame('fixel-fox', $bot['slug']);
        Bots::setSecret((int) $bot['id'], 'bot_token', 'xoxb-123');
        $bot = Bots::find((int) $bot['id']);
        $this->assertStringNotContainsString('xoxb-123', $bot['bot_token_enc']);
        $this->assertSame('xoxb-123', Bots::secret($bot, 'bot_token'));
        $public = Bots::public($bot);
        $this->assertArrayNotHasKey('bot_token_enc', $public);
        $this->assertTrue($public['has_bot_token']);
    }

    public function testSlugsAreUnique(): void
    {
        Bots::create(['name' => 'Duck']);
        $this->assertSame('duck-2', Bots::create(['name' => 'Duck'])['slug']);
    }

    public function testRejectsBadColor(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Bots::create(['name' => 'X', 'background_color' => 'red']);
    }

    public function testManifest(): void
    {
        $bot = Bots::create(['name' => 'Doughbert', 'short_description' => 'Ideas.', 'long_description' => 'Too short', 'background_color' => '#3C5A5C']);
        $m = Manifest::build($bot, 'https://studio.example.com');
        $this->assertSame('Doughbert', $m['display_information']['name']);
        $this->assertSame('#3c5a5c', $m['display_information']['background_color']);
        $this->assertArrayNotHasKey('long_description', $m['display_information'], 'Slack rejects long descriptions under 174 characters');
        $this->assertSame('https://studio.example.com/events.php?bot=doughbert', $m['settings']['event_subscriptions']['request_url']);
        $this->assertSame(['https://studio.example.com/oauth.php'], $m['oauth_config']['redirect_urls']);
        $this->assertFalse($m['settings']['socket_mode_enabled']);
        $this->assertContains('im:history', $m['oauth_config']['scopes']['bot']);
    }

    public function testRenderGrid(): void
    {
        $rows = array_fill(0, 32, str_repeat('.', 8) . str_repeat('0', 8) . str_repeat('1', 8) . str_repeat('.', 8));
        $png = Generator::renderGrid(['#000000', '#ff0000'], $rows);
        $info = getimagesizefromstring($png);
        $this->assertSame([32, 32], [$info[0], $info[1]]);
        $img = imagecreatefromstring($png);
        $this->assertSame(127, (imagecolorat($img, 0, 0) >> 24) & 0x7f, 'dots are transparent');
        $this->assertSame(0xff0000, imagecolorat($img, 20, 5) & 0xffffff);
    }

    public function testRenderGridRejectsGarbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Generator::renderGrid(['#000000'], ['....']);
    }

    public function testCleanSvgStripsScriptsAndHandlers(): void
    {
        $svg = Generator::cleanSvg('Here: <svg viewBox="0 0 512 512"><script>alert(1)</script><rect onclick="x()" width="10" fill="#fff"/><image href="http://evil"/><use href="#a"/></svg> done');
        $this->assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg"', $svg);
        $this->assertStringNotContainsString('script', $svg);
        $this->assertStringNotContainsString('onclick', $svg);
        $this->assertStringNotContainsString('evil', $svg);
        $this->assertStringContainsString('href="#a"', $svg);
    }

    public function testImageAbilityAddsFilePermission(): void
    {
        $bot = Bots::create(['name' => 'Eye', 'can_see_images' => true]);
        $this->assertContains('files:read', Manifest::build($bot, 'https://s.example')['oauth_config']['scopes']['bot']);
        $plain = Bots::create(['name' => 'Blind']);
        $this->assertNotContains('files:read', Manifest::scopes($plain));
        $this->assertStringContainsString('files%3Aread', Manifest::authorizeUrl($bot + ['client_id' => '1.2'], 'st', 'https://s.example'));
    }

    public function testNeedsReinstallWhenPermissionMissing(): void
    {
        $bot = Bots::create(['name' => 'Eye', 'can_see_images' => true]);
        Bots::setSystem((int) $bot['id'], ['status' => 'installed', 'granted_scopes' => 'chat:write,im:history']);
        $this->assertTrue(Bots::public(Bots::find((int) $bot['id']))['needs_reinstall']);
        Bots::setSystem((int) $bot['id'], ['granted_scopes' => 'chat:write,files:read']);
        $this->assertFalse(Bots::public(Bots::find((int) $bot['id']))['needs_reinstall']);
    }

    public function testWebToolsPickVersionByModel(): void
    {
        $haiku = \Studio\Ai\ClaudeProvider::webTools('claude-haiku-5-5', ['read_links' => true, 'search_web' => true]);
        $this->assertSame(['web_fetch_20250910', 'web_search_20250305'], array_column($haiku, 'type'));
        $opus = \Studio\Ai\ClaudeProvider::webTools('claude-opus-5-5', ['read_links' => true]);
        $this->assertSame(['web_fetch_20260209'], array_column($opus, 'type'));
        $this->assertSame([], \Studio\Ai\ClaudeProvider::webTools('claude-haiku-5-5', []));
    }

    public function testRemovesFullCanvasBackgroundOnly(): void
    {
        $svg = '<svg viewBox="0 0 512 512"><rect width="512" height="512" fill="#123"/><rect x="0" y="0" width="100%" height="100%" fill="#fff"></rect><rect x="40" y="40" width="100" height="100" fill="#f00"/></svg>';
        $out = Generator::removeBackgroundRect($svg);
        $this->assertStringNotContainsString('#123', $out);
        $this->assertStringNotContainsString('#fff', $out);
        $this->assertStringContainsString('#f00', $out);
    }

    public function testRemoveBackgroundCutsOutFlatColorFromEdges(): void
    {
        // Magenta background, a white "character" in the middle, and a magenta dot inside it that must survive.
        $img = imagecreatetruecolor(100, 100);
        imagefill($img, 0, 0, imagecolorallocate($img, 250, 5, 250));
        imagefilledellipse($img, 50, 50, 60, 60, imagecolorallocate($img, 255, 255, 255));
        imagefilledellipse($img, 50, 50, 6, 6, imagecolorallocate($img, 255, 0, 255));
        ob_start();
        imagepng($img);
        $out = imagecreatefromstring(Generator::removeBackground((string) ob_get_clean()));
        $alpha = fn (int $x, int $y) => (imagecolorat($out, $x, $y) >> 24) & 0x7f;
        $this->assertSame(127, $alpha(2, 2), 'background is transparent');
        $this->assertSame(0, $alpha(30, 50), 'character stays opaque');
        $this->assertSame(0, $alpha(50, 50), 'background-colored detail inside the character is kept');
    }
}
