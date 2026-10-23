<?php
declare(strict_types=1);

namespace Studio\Ai;

interface Provider
{
    /**
     * @param array $messages [['role' => 'user'|'assistant', 'content' => string|array], ...]
     *   Array content is a list of parts: ['type' => 'text', 'text' => ...] or ['type' => 'image', 'mime' => ..., 'data' => base64].
     * @param array $options  'read_links' => bool, 'search_web' => bool (providers that can't do these ignore them)
     */
    public function chat(string $system, array $messages, string $model, array $options = []): string;

    /**
     * Returns data matching $schema (JSON Schema, every property required, no extras).
     * $effort (low|medium|high) trades thinking time for quality where the provider supports it.
     */
    public function json(string $system, string $prompt, array $schema, string $model, string $effort = 'medium'): array;
}
