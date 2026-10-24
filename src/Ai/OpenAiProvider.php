<?php
declare(strict_types=1);

namespace Studio\Ai;

/** OpenAI through its REST API (Chat Completions and Images). */
final class OpenAiProvider implements Provider
{
    public function __construct(private string $apiKey)
    {
    }

    /** Reading links and web search aren't wired up for OpenAI yet; the studio only offers them for Claude. */
    public function chat(string $system, array $messages, string $model, array $options = []): string
    {
        $messages = array_map(function (array $m) {
            if (is_array($m['content'])) {
                $m['content'] = array_map(fn ($p) => $p['type'] === 'image'
                    ? ['type' => 'image_url', 'image_url' => ['url' => "data:{$p['mime']};base64,{$p['data']}"]]
                    : ['type' => 'text', 'text' => $p['text']], $m['content']);
            }
            return $m;
        }, $messages);
        $data = $this->post('chat/completions', [
            'model' => $model,
            'messages' => [['role' => 'system', 'content' => $system], ...$messages],
        ]);
        return $this->content($data);
    }

    public function json(string $system, string $prompt, array $schema, string $model, string $effort = 'medium'): array
    {
        $data = $this->post('chat/completions', [
            'model' => $model,
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $prompt]],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'result', 'strict' => true, 'schema' => $schema],
            ],
        ]);
        $parsed = json_decode($this->content($data), true);
        if (!is_array($parsed)) {
            throw new AiError('OpenAI returned something that is not JSON.', 'The AI returned an unreadable answer. Try again.');
        }
        return $parsed;
    }

    /**
     * Returns PNG bytes. With $transparent, asks for a transparent background; models that can't do that
     * (like gpt-image-2) throw TransparencyUnsupported so the caller can use another approach.
     */
    public function image(string $prompt, string $model, bool $transparent = false): string
    {
        $request = ['model' => $model, 'prompt' => $prompt, 'size' => '1024x1024', 'n' => 1];
        if ($transparent) {
            $request['background'] = 'transparent';
        }
        try {
            $data = $this->post('images/generations', $request, 180);
        } catch (AiError $e) {
            if ($transparent && str_contains(strtolower($e->getMessage()), 'transparent')) {
                throw new TransparencyUnsupported($e->getMessage());
            }
            throw $e;
        }
        $b64 = $data['data'][0]['b64_json'] ?? null;
        if (!is_string($b64)) {
            throw new AiError('OpenAI returned no image.', 'The image generator returned nothing. Try again.');
        }
        return base64_decode($b64);
    }

    private function content(array $data): string
    {
        $message = $data['choices'][0]['message'] ?? [];
        if (!empty($message['refusal'])) {
            throw new AiError('OpenAI declined the request.', "I can't help with that one.");
        }
        $text = trim((string) ($message['content'] ?? ''));
        if ($text === '') {
            throw new AiError('OpenAI returned no text.', '(I came up empty. Try asking another way.)');
        }
        return $text;
    }

    private function post(string $path, array $body, int $timeout = 120): array
    {
        $ch = curl_init("https://api.openai.com/v1/$path");
        curl_setopt_array($ch, [
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->apiKey, 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new AiError("Could not reach OpenAI: $err", "I couldn't reach the OpenAI API. Try again in a bit.");
        }
        $data = json_decode((string) $raw, true) ?: [];
        if ($status >= 400) {
            $msg = $data['error']['message'] ?? "HTTP $status";
            $code = (string) ($data['error']['code'] ?? $data['error']['type'] ?? '');
            $friendly = match (true) {
                $status === 401 => 'The OpenAI API key was rejected. Check config.php.',
                // OpenAI uses 429 both for "slow down" and for "out of credits".
                $code === 'insufficient_quota' || str_contains(strtolower($msg), 'credit') || str_contains(strtolower($msg), 'quota')
                    => 'The OpenAI account is out of credits. Add credits at platform.openai.com → Settings → Billing.',
                $status === 403 => 'OpenAI refused the request: ' . $msg,
                $status === 429 => "I'm getting too many requests right now. Try again in a minute.",
                default => 'Something went wrong talking to my brain. Check the studio log.',
            };
            throw new AiError("OpenAI error: $msg", $friendly);
        }
        return $data;
    }
}
