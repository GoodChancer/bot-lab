<?php
declare(strict_types=1);

namespace Studio\Ai;

/** The image model can't return a transparent background. */
final class TransparencyUnsupported extends \RuntimeException
{
}
