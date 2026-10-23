<?php
declare(strict_types=1);

namespace Studio\Tests;

use PHPUnit\Framework\TestCase;
use Studio\Slack\Events;

final class EventsTest extends TestCase
{
    private function sign(string $body, int $ts, string $secret): string
    {
        return 'v0=' . hash_hmac('sha256', "v0:$ts:$body", $secret);
    }

    public function testValidSignaturePasses(): void
    {
        $now = 1_800_000_000;
        $this->assertTrue(Events::verify('{"a":1}', (string) $now, $this->sign('{"a":1}', $now, 's3cret'), 's3cret', $now));
    }

    public function testWrongSecretFails(): void
    {
        $now = 1_800_000_000;
        $this->assertFalse(Events::verify('{"a":1}', (string) $now, $this->sign('{"a":1}', $now, 'other'), 's3cret', $now));
    }

    public function testStaleTimestampFails(): void
    {
        $now = 1_800_000_000;
        $old = $now - 301;
        $this->assertFalse(Events::verify('x', (string) $old, $this->sign('x', $old, 's'), 's', $now));
    }

    public function testEmptySecretFails(): void
    {
        $now = 1_800_000_000;
        $this->assertFalse(Events::verify('x', (string) $now, $this->sign('x', $now, ''), '', $now));
    }

    public function testShouldReply(): void
    {
        $this->assertTrue(Events::shouldReply(['type' => 'message', 'channel_type' => 'im', 'text' => 'hi']));
        $this->assertTrue(Events::shouldReply(['type' => 'app_mention', 'text' => 'hi']));
        $this->assertFalse(Events::shouldReply(['type' => 'message', 'channel_type' => 'channel']));
        $this->assertFalse(Events::shouldReply(['type' => 'message', 'channel_type' => 'im', 'bot_id' => 'B1']));
        $this->assertFalse(Events::shouldReply(['type' => 'message', 'channel_type' => 'im', 'subtype' => 'message_changed']));
    }

    public function testConversationMergesAndStartsWithUser(): void
    {
        $msgs = [
            ['bot_id' => 'B', 'text' => 'welcome'],          // dropped: conversation must start with the user
            ['user' => 'U1', 'text' => 'hi'],
            ['user' => 'UBOT', 'text' => 'hello'],
            ['user' => 'U1', 'text' => 'a'],
            ['user' => 'U1', 'text' => '<@UBOT> b'],         // merged, mention removed
            ['user' => 'U1', 'subtype' => 'channel_join', 'text' => 'joined'],
        ];
        $this->assertSame([
            ['role' => 'user', 'content' => 'hi'],
            ['role' => 'assistant', 'content' => 'hello'],
            ['role' => 'user', 'content' => "a\n\nb"],
        ], Events::toConversation($msgs, 'UBOT'));
    }

    public function testFileSharesAreReplyableAndLinksAreCleaned(): void
    {
        $this->assertTrue(Events::shouldReply(['type' => 'message', 'channel_type' => 'im', 'subtype' => 'file_share']));
        $this->assertSame('see https://example.com/a?b=1 ok', Events::clean('<@UBOT> see <https://example.com/a?b=1|example.com> ok', 'UBOT'));
        $this->assertSame('https://x.dev', Events::clean('<https://x.dev>', ''));
    }

    public function testImagesFiltersToSupportedFiles(): void
    {
        $msg = ['files' => [
            ['mimetype' => 'image/png', 'size' => 1000, 'url_private' => 'https://files.slack.com/a.png'],
            ['mimetype' => 'application/pdf', 'size' => 1000, 'url_private' => 'https://files.slack.com/a.pdf'],
            ['mimetype' => 'image/jpeg', 'size' => 9_000_000, 'url_private' => 'https://files.slack.com/big.jpg'],
        ]];
        $this->assertCount(1, Events::images($msg));
    }

    public function testSharedFilesNotedInHistory(): void
    {
        $conv = Events::toConversation([['user' => 'U1', 'subtype' => 'file_share', 'text' => 'look', 'files' => [[]]]], 'UBOT');
        $this->assertSame("look\n[shared a file]", $conv[0]['content']);
    }
}
