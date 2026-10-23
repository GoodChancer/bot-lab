<?php
declare(strict_types=1);

namespace Studio\Slack;

final class SlackError extends \RuntimeException
{
    public function __construct(public readonly string $method, public readonly string $error, public readonly array $response = [])
    {
        $detail = '';
        // apps.manifest.* explain manifest problems field by field.
        foreach ($response['errors'] ?? [] as $e) {
            $detail .= "\n- " . ($e['pointer'] ?? '') . ': ' . ($e['message'] ?? '');
        }
        parent::__construct("Slack $method failed: $error$detail");
    }
}
