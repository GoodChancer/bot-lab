<?php
declare(strict_types=1);

namespace Studio\Ai;

/** An AI call failed. $friendly is safe to show in Slack or the studio. */
final class AiError extends \RuntimeException
{
    public function __construct(string $detail, public readonly string $friendly = 'Something went wrong talking to my brain. Check the studio log.')
    {
        parent::__construct($detail);
    }
}
