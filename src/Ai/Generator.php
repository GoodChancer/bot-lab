<?php
declare(strict_types=1);

namespace Studio\Ai;

use Studio\Config;

/** Writes bot personas and draws avatars. */
final class Generator
{
    /**
     * Avatar styles. "claude" says how Claude can draw it (it can't make images, but it can
     * write a pixel grid or an SVG); null means it needs an image model (OpenAI).
     */
    public const STYLES = [
        'none' => ['label' => 'No style', 'claude' => 'svg',
            'prompt' => 'Character portrait. Head and shoulders, facing the viewer.',
            'svg' => 'Draw in whatever illustration style best suits the character.'],
        'pixel' => ['label' => 'Pixel art', 'claude' => 'grid',
            'prompt' => 'Retro 32x32 pixel art character portrait, bold black outline, limited palette, chunky pixels, like a classic 16-bit RPG sprite. Head and shoulders, facing the viewer.'],
        'vector' => ['label' => 'Flat vector', 'claude' => 'svg',
            'prompt' => 'Flat vector mascot illustration, simple geometric shapes, bold clean colors, no gradients, like a friendly app mascot. Head and shoulders, facing the viewer.',
            'svg' => 'Draw a friendly flat-vector mascot: simple shapes, bold flat colors, no outlines or only very subtle ones.'],
        'line' => ['label' => 'Line art', 'claude' => 'svg',
            'prompt' => 'Bold monoline line-art character portrait, thick uniform dark outlines with rounded ends, one or two flat accent colors, lots of clean negative space, like a modern sticker or app icon. Head and shoulders, facing the viewer.',
            'svg' => 'Draw in bold monoline line art: thick uniform dark strokes (stroke-width 10-14, round caps and joins), mostly unfilled shapes, plus one or two flat accent colors.'],
        'lowpoly' => ['label' => 'Low poly', 'claude' => 'svg',
            'prompt' => 'Low-poly geometric character portrait made of flat-shaded triangles and facets, crisp edges, soft light from one side. Head and shoulders, facing the viewer.',
            'svg' => 'Draw in a low-poly geometric style: build the character only from flat-colored triangles and polygons (<polygon>), about 40-120 facets, with lighter facets on one side and darker on the other to suggest a light source.'],
        'painted' => ['label' => 'Painted', 'claude' => null,
            'prompt' => 'Storybook watercolor and gouache character portrait, soft brush texture, warm light. Head and shoulders, facing the viewer.'],
        'clay' => ['label' => '3D clay', 'claude' => null,
            'prompt' => 'Cute 3D claymation character portrait, soft studio lighting, plasticine texture, rounded shapes. Head and shoulders, facing the viewer.'],
    ];

    public static function profileSchema(): array
    {
        $s = fn (string $d) => ['type' => 'string', 'description' => $d];
        return [
            'type' => 'object',
            'properties' => [
                'name' => $s('Short, memorable character name, at most 30 characters. Playful alliteration or puns welcome.'),
                'personality' => $s('The bot\'s system prompt, written as described in your instructions (120-250 words).'),
                'short_description' => $s('One line for the Slack app directory, at most 130 characters.'),
                'long_description' => $s('Slack app long description, 250-700 characters: who the character is, what to ask it, how to use it (DM it, or @mention it in a channel).'),
                'background_color' => $s('Hex color like #1b2440 that suits the character. It is the banner of the bot\'s Slack profile card, with white text on top, so choose a medium or dark color that white text is readable on.'),
                'avatar_description' => $s('Visual description of the character for an artist: species or type, colors, clothing, props, expression. One or two sentences, no style words.'),
            ],
            'required' => ['name', 'personality', 'short_description', 'long_description', 'background_color', 'avatar_description'],
            'additionalProperties' => false,
        ];
    }

    private const DESIGN_GUIDE = <<<'TXT'
        You design characters for Slack bots: AI teammates with a distinct personality, a name, and a look.
        Characters should be fun and memorable, but genuinely useful at their job. Usefulness comes first.

        How to write the personality (the bot's system prompt):
        - Second person ("You are..."). Open with one line on who the character is.
        - Be concrete about the job: what to look for, what a great answer contains, and how a typical reply is shaped
          (for example: lead with the verdict, then 2-4 specific points, then one next step).
        - Give the voice 2-3 concrete traits (word choice, kind of humor, how blunt or warm) rather than adjectives alone.
        - Say what to avoid: filler like "Great question!", padding, inventing facts, piles of emoji. Ask one short
          clarifying question when a request is too vague to do well.
        - Replies happen in Slack chat, so tell it to keep them short and skimmable.
        - 120-250 words. No headings, no bullet-point dump of rules.

        How to write the descriptions:
        - Short description: what it does for you, in plain words. No "AI-powered", no repeating the name.
        - Long description: two or three short paragraphs: who the character is, what to bring it, how to use it
          (DM it, or invite it to a channel and @mention it), and one example of something to ask.

        Respect what the person asked for. If they named a name, tone or detail, use it.
        TXT;

    /** Fills in a whole bot from a role description like "a blunt critic for my writing". */
    public static function profile(string $provider, string $role): array
    {
        $prompt = "Design a Slack bot character for this role:\n\n<role>\n" . trim($role) . "\n</role>";
        $data = Ai::provider($provider)->json(self::DESIGN_GUIDE, $prompt, self::profileSchema(), Ai::generateModel($provider));
        return self::tidyProfile($data);
    }

    /** Changes an existing bot as asked ("shorter instructions, more sarcastic"), leaving the rest alone. */
    public static function refine(string $provider, array $bot, string $request): array
    {
        $current = [];
        foreach (array_keys(self::profileSchema()['properties']) as $field) {
            $current[$field] = (string) ($bot[$field] ?? '');
        }
        $prompt = "Here is a Slack bot character:\n\n<current_bot>\n" . json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\n</current_bot>\n\nChange it as requested:\n\n<change_request>\n" . trim($request) . "\n</change_request>\n\n"
            . 'Return the whole bot. Copy every field the request does not affect exactly as it is. '
            . 'Fill in any empty fields so the bot is complete.';
        $data = Ai::provider($provider)->json(self::DESIGN_GUIDE, $prompt, self::profileSchema(), Ai::generateModel($provider));
        return self::tidyProfile($data);
    }

    private static function tidyProfile(array $data): array
    {
        // Keep within Slack's limits even if the model ran long.
        $data['name'] = mb_substr(trim((string) ($data['name'] ?? '')), 0, 35);
        $data['short_description'] = mb_substr(trim((string) ($data['short_description'] ?? '')), 0, 139);
        $data['long_description'] = mb_substr(trim((string) ($data['long_description'] ?? '')), 0, 4000);
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($data['background_color'] ?? ''))) {
            $data['background_color'] = '#1b2440';
        }
        return $data;
    }

    /**
     * Draws an avatar. Returns ['mime' => ..., 'data' => base64] for the cropper.
     * Uses OpenAI images when available (or required); otherwise Claude draws a grid or SVG.
     */
    public static function avatar(string $style, string $description, string $background, string $prefer = 'anthropic'): array
    {
        $info = self::STYLES[$style] ?? throw new \InvalidArgumentException("Unknown style $style");
        $useOpenAi = Ai::available('openai') && ($prefer === 'openai' || $info['claude'] === null || !Ai::available('anthropic'));
        if (!$useOpenAi && $info['claude'] === null) {
            throw new AiError('Style needs an image model.', 'The ' . $info['label'] . ' style needs an OpenAI API key.');
        }

        if ($useOpenAi) {
            /** @var OpenAiProvider $openai */
            $openai = Ai::provider('openai');
            $prompt = $info['prompt'] . "\n\nCharacter: $description\n\nPlain solid $background background filling the whole image. Centered, with some space around the character. No text, letters, or watermark.";
            return ['mime' => 'image/png', 'data' => base64_encode($openai->image($prompt, (string) Config::get('models.image.openai')))];
        }

        return $info['claude'] === 'grid'
            ? ['mime' => 'image/png', 'data' => base64_encode(self::pixelGrid($description, $background))]
            : ['mime' => 'image/svg+xml', 'data' => base64_encode(self::svg($info['svg'], $description, $background))];
    }

    // ---- Claude draws pixel art as a grid ---------------------------------------------------

    public const GRID = 32;

    public static function gridSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'palette' => [
                    'type' => 'array',
                    'description' => 'Up to 16 colors as hex (#rrggbb). Index 0 is used by the digit 0, 10 by a, and so on.',
                    'items' => ['type' => 'string'],
                ],
                'rows' => [
                    'type' => 'array',
                    'description' => '32 strings of exactly 32 characters each. "." is transparent; 0-9 and a-f pick a palette color.',
                    'items' => ['type' => 'string'],
                ],
            ],
            'required' => ['palette', 'rows'],
            'additionalProperties' => false,
        ];
    }

    private static function pixelGrid(string $description, string $background): string
    {
        $system = 'You are a pixel artist who draws 32x32 sprites by writing them out as a grid of characters. '
            . 'You draw clear, readable character portraits with a 1-pixel dark outline, 2-3 shades per color for shading, and a highlight.';
        $prompt = "Draw a 32x32 pixel art avatar (head and shoulders, facing the viewer, centered, with 2-4 pixels of transparent margin) of:\n\n"
            . "<character>\n$description\n</character>\n\n"
            . "The avatar will sit on a $background background, so make sure the character contrasts with it. "
            . "Write 32 rows of exactly 32 characters. Use \".\" for transparent pixels and 0-9, a-f for palette colors.";
        $provider = Ai::provider('anthropic');
        $model = Ai::generateModel('anthropic');
        $last = null;
        for ($try = 0; $try < 2; $try++) {
            $data = $provider->json($system, $prompt, self::gridSchema(), $model, 'low');
            try {
                return self::renderGrid($data['palette'] ?? [], $data['rows'] ?? []);
            } catch (\InvalidArgumentException $e) {
                $last = $e;
            }
        }
        throw new AiError('Pixel grid invalid: ' . $last?->getMessage(), 'The pixel art came out malformed. Try again.');
    }

    /** Validates a grid and renders it as a 32x32 PNG with transparency. Short rows are padded. */
    public static function renderGrid(array $palette, array $rows): string
    {
        $palette = array_values(array_filter($palette, fn ($c) => is_string($c) && preg_match('/^#?[0-9a-fA-F]{6}$/', $c)));
        if (!$palette || count($palette) > 16) {
            throw new \InvalidArgumentException('Palette needs 1-16 hex colors.');
        }
        if (count($rows) < self::GRID - 4 || count($rows) > self::GRID) {
            throw new \InvalidArgumentException('Expected 32 rows, got ' . count($rows) . '.');
        }
        $img = imagecreatetruecolor(self::GRID, self::GRID);
        imagesavealpha($img, true);
        imagealphablending($img, false);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        $colors = [];
        foreach ($palette as $i => $hex) {
            $hex = ltrim($hex, '#');
            $colors[dechex($i)] = imagecolorallocate($img, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
        }
        $painted = 0;
        foreach (array_values($rows) as $y => $row) {
            $row = strtolower((string) $row);
            if (strlen($row) > self::GRID + 2) {
                throw new \InvalidArgumentException("Row $y is too long.");
            }
            for ($x = 0; $x < min(self::GRID, strlen($row)); $x++) {
                $ch = $row[$x];
                if (isset($colors[$ch])) {
                    imagesetpixel($img, $x, $y, $colors[$ch]);
                    $painted++;
                }
            }
        }
        if ($painted < 60) {
            throw new \InvalidArgumentException('The drawing is nearly empty.');
        }
        ob_start();
        imagepng($img);
        return (string) ob_get_clean();
    }

    // ---- Claude draws flat vector art as SVG -------------------------------------------------

    private static function svg(string $styleGuide, string $description, string $background): string
    {
        $schema = [
            'type' => 'object',
            'properties' => ['svg' => ['type' => 'string', 'description' => 'A complete <svg> element with viewBox="0 0 512 512".']],
            'required' => ['svg'],
            'additionalProperties' => false,
        ];
        $system = 'You are an illustrator who draws character avatars directly in SVG. '
            . 'Use only basic shapes and paths (circle, ellipse, rect, polygon, path, g). No text, images, scripts, filters or external references.';
        $prompt = "Draw an avatar (head and shoulders, facing the viewer, centered) of:\n\n<character>\n$description\n</character>\n\n"
            . "Style: $styleGuide\n\n"
            . "Use viewBox=\"0 0 512 512\" and start with a full-size rect filled $background as the background. Leave about 40px of space around the character.";
        $data = Ai::provider('anthropic')->json($system, $prompt, $schema, Ai::generateModel('anthropic'), 'low');
        return self::cleanSvg((string) ($data['svg'] ?? ''));
    }

    /** Keeps only drawing elements: no scripts, event handlers, external links or embedded content. */
    public static function cleanSvg(string $svg): string
    {
        if (!preg_match('/<svg\b.*<\/svg>/si', $svg, $m)) {
            throw new AiError('No <svg> in the answer.', 'The vector drawing came out malformed. Try again.');
        }
        $svg = $m[0];
        $svg = preg_replace('#<(script|foreignObject|image|iframe|style)\b.*?</\1>#si', '', $svg);
        $svg = preg_replace('#<(script|foreignObject|image|iframe|style)\b[^>]*/>#si', '', $svg);
        $svg = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $svg);
        $svg = preg_replace('/\s(xlink:)?href\s*=\s*("(?!#)[^"]*"|\'(?!#)[^\']*\')/i', '', $svg);
        if (!str_contains($svg, 'xmlns=')) {
            $svg = preg_replace('/<svg\b/i', '<svg xmlns="http://www.w3.org/2000/svg"', $svg, 1);
        }
        return $svg;
    }
}
