<?php
declare(strict_types=1);

namespace Studio\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;

/** Claude through Anthropic's official PHP SDK. */
final class ClaudeProvider implements Provider
{
    /** Models that support server-side refusal fallbacks ('default' routing). */
    private const FALLBACK_MODELS = ['claude-fable-5-1', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5'];

    /** Models that support the newer web tools (with dynamic filtering); others use the basic versions. */
    private const DYNAMIC_WEB_MODELS = ['claude-fable-5-1', 'claude-fable-5', 'claude-opus-5-5', 'claude-opus-5', 'claude-opus-4-8',
        'claude-opus-4-7', 'claude-opus-4-6', 'claude-sonnet-5-5', 'claude-sonnet-5', 'claude-sonnet-4-6'];

    private Client $client;

    public function __construct(string $apiKey)
    {
        $this->client = new Client(apiKey: $apiKey);
    }

    public function chat(string $system, array $messages, string $model, array $options = []): string
    {
        $message = $this->create($model, $system, $this->toClaude($messages), ['effort' => 'low'], 4000, self::webTools($model, $options));
        return $this->text($message);
    }

    /** Anthropic's server-side web tools: Claude fetches pages and searches itself. */
    public static function webTools(string $model, array $options): array
    {
        $dynamic = in_array($model, self::DYNAMIC_WEB_MODELS, true);
        $tools = [];
        if (!empty($options['read_links'])) {
            // Web fetch only opens URLs that already appear in the conversation.
            $tools[] = ['type' => $dynamic ? 'web_fetch_20260209' : 'web_fetch_20250910', 'name' => 'web_fetch', 'maxUses' => 3, 'maxContentTokens' => 20000];
        }
        if (!empty($options['search_web'])) {
            $tools[] = ['type' => $dynamic ? 'web_search_20260209' : 'web_search_20250305', 'name' => 'web_search', 'maxUses' => 3];
        }
        return $tools;
    }

    /** Converts our message parts to Claude content blocks. */
    private function toClaude(array $messages): array
    {
        return array_map(function (array $m) {
            if (!is_array($m['content'])) {
                return $m;
            }
            $m['content'] = array_map(fn ($p) => $p['type'] === 'image'
                ? ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $p['mime'], 'data' => $p['data']]]
                : ['type' => 'text', 'text' => $p['text']], $m['content']);
            return $m;
        }, $messages);
    }

    public function json(string $system, string $prompt, array $schema, string $model, string $effort = 'medium'): array
    {
        $message = $this->create(
            $model,
            $system,
            [['role' => 'user', 'content' => $prompt]],
            ['effort' => $effort, 'format' => ['type' => 'json_schema', 'schema' => $schema]],
            16000,
        );
        $data = json_decode($this->text($message), true);
        if (!is_array($data)) {
            throw new AiError('Claude returned something that is not JSON.', 'The AI returned an unreadable answer. Try again.');
        }
        return $data;
    }

    private function create(string $model, string $system, array $messages, array $outputConfig, int $maxTokens, array $tools = []): object
    {
        $fallback = in_array($model, self::FALLBACK_MODELS, true);
        try {
            // Web tools run on Anthropic's side; a long run can pause, and resending the turn resumes it.
            for ($continuations = 0; ; $continuations++) {
                $message = $this->client->beta->messages->create(
                    model: $model,
                    maxTokens: $maxTokens,
                    system: $system,
                    messages: $messages,
                    thinking: ['type' => 'adaptive'],
                    outputConfig: $outputConfig,
                    tools: $tools ?: null,
                    fallbacks: $fallback ? 'default' : null,
                    betas: $fallback ? ['server-side-fallback-2026-07-01'] : null,
                );
                $stop = is_object($message->stopReason) ? $message->stopReason->value : (string) $message->stopReason;
                if ($stop !== 'pause_turn' || $continuations >= 3) {
                    break;
                }
                $messages[] = ['role' => 'assistant', 'content' => $message->content];
            }
        } catch (RateLimitException $e) {
            throw new AiError('Claude rate limit: ' . $e->getMessage(), "I'm getting too many requests right now. Try again in a minute.");
        } catch (AuthenticationException $e) {
            throw new AiError('Claude rejected the API key: ' . $e->getMessage(), 'The Anthropic API key was rejected. Check config.php.');
        } catch (APIStatusException $e) {
            throw new AiError('Claude API error: ' . $e->getMessage());
        } catch (APIConnectionException $e) {
            throw new AiError('Could not reach Claude: ' . $e->getMessage(), "I couldn't reach the Claude API. Try again in a bit.");
        }

        $stop = is_object($message->stopReason) ? $message->stopReason->value : (string) $message->stopReason;
        if ($stop === 'refusal') {
            throw new AiError('Claude declined the request.', "I can't help with that one.");
        }
        if ($stop === 'max_tokens') {
            throw new AiError('Claude ran out of room.', 'The AI answer was cut off. Try again.');
        }
        return $message;
    }

    private function text(object $message): string
    {
        // With web tools, Claude may write a short "let me look that up" before searching, then the real answer.
        // Keep only the answer: the text after the last tool block. (Text with citations comes as several
        // consecutive text blocks, so they're joined without separators.)
        $start = 0;
        foreach ($message->content as $i => $block) {
            if ($block->type === 'server_tool_use' || str_ends_with((string) $block->type, '_tool_result')) {
                $start = $i + 1;
            }
        }
        $text = '';
        foreach (array_slice($message->content, $start) as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        $text = trim($text);
        if ($text === '') {
            throw new AiError('Claude returned no text.', '(I came up empty. Try asking another way.)');
        }
        return $text;
    }
}
