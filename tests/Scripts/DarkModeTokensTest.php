<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Dark mode follows the viewer's prefers-color-scheme. Every colour of the
 * page comes from resources/css/tokens.css, which gives each colour, shadow
 * and gradient token a dark value, so no component keeps a light colour in
 * the dark scheme. The test reads files only (group scripts).
 */
#[Group('scripts')]
final class DarkModeTokensTest extends TestCase
{
    /**
     * Rejects: a token file without `color-scheme: light dark` on :root (the
     * scrollbars and form controls stay light), without a
     * prefers-color-scheme dark block, and a colour, shadow or gradient token
     * that has no dark value (a new status colour added only for light).
     * Still passes: a dark value that equals the light one, or one with poor
     * contrast (the contrast test checks body text only).
     */
    public function test_every_colour_token_has_a_dark_value(): void
    {
        $css = $this->tokens();
        [$light, $dark] = $this->blocks($css);

        self::assertMatchesRegularExpression('/color-scheme:\s*light dark;/', $light);

        $themed = array_filter(
            array_keys($this->declarations($light)),
            static fn (string $name): bool => preg_match('/^--(color|shadow|gradient)-/', $name) === 1,
        );
        self::assertGreaterThanOrEqual(20, count($themed));

        $darkNames = array_keys($this->declarations($dark));
        $missing = array_values(array_diff($themed, $darkNames));
        self::assertSame([], $missing, 'tokens without a dark value');
        self::assertSame([], array_values(array_diff($darkNames, $themed)), 'dark tokens without a light value');
    }

    /**
     * Rejects: dark body text, secondary text or tertiary text below WCAG AA
     * (4.5:1) on the dark background, the sidebar or the subtle surface, and
     * light text below AA for the body and the secondary text.
     * Still passes: a status colour with poor contrast on its tint.
     */
    public function test_body_text_has_aa_contrast_in_both_schemes(): void
    {
        [$light, $dark] = $this->blocks($this->tokens());
        $l = $this->declarations($light);
        $d = $this->declarations($dark);

        foreach (['--color-text', '--color-text-secondary'] as $text) {
            foreach (['--color-bg', '--color-sidebar', '--color-subtle'] as $surface) {
                self::assertGreaterThanOrEqual(4.5, $this->contrast($l[$text], $l[$surface]), "light {$text} on {$surface}");
            }
        }

        foreach (['--color-text', '--color-text-secondary', '--color-text-tertiary', '--color-accent', '--color-warning-text', '--color-danger-text', '--color-success-text'] as $text) {
            foreach (['--color-bg', '--color-sidebar', '--color-subtle'] as $surface) {
                self::assertGreaterThanOrEqual(4.5, $this->contrast($d[$text], $d[$surface]), "dark {$text} on {$surface}");
            }
        }
    }

    /**
     * Rejects: white text (`--color-on-accent`) below WCAG AA (4.5:1) on the
     * accent fill of a primary button in either scheme (the old #0A84FF and
     * #3890F5), a light accent below AA as link text on the page, the
     * sidebar or the subtle surface (the old #0A84FF), and an on-accent
     * colour that is not white.
     * Still passes: an accent of another hue, or a dark accent that is
     * readable as text but too light for white text, as long as the fill
     * token carries the white text.
     */
    public function test_accent_has_aa_contrast_for_white_text_and_as_text(): void
    {
        [$light, $dark] = $this->blocks($this->tokens());

        foreach (['light' => $this->declarations($light), 'dark' => $this->declarations($dark)] as $scheme => $tokens) {
            self::assertArrayHasKey('--color-accent-fill', $tokens, "{$scheme} accent fill token");
            self::assertSame('#FFFFFF', strtoupper($tokens['--color-on-accent']), "{$scheme} on-accent is white");
            self::assertGreaterThanOrEqual(4.5, $this->contrast($tokens['--color-on-accent'], $tokens['--color-accent-fill']), "{$scheme} white on --color-accent-fill");

            foreach (['--color-bg', '--color-sidebar', '--color-subtle'] as $surface) {
                self::assertGreaterThanOrEqual(4.5, $this->contrast($tokens['--color-accent'], $tokens[$surface]), "{$scheme} --color-accent on {$surface}");
            }
        }
    }

    /**
     * Rejects: a style rule of a `.vue` file that puts `--color-on-accent`
     * text or glyphs on a `--color-accent` background (the dark text accent
     * is too light for white text) instead of `--color-accent-fill`.
     * Still passes: white text on an accent background that another rule
     * sets for the same element.
     */
    public function test_white_on_accent_uses_the_fill_token(): void
    {
        $root = dirname(__DIR__, 2).'/resources/js';
        $found = [];
        $fills = 0;

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'vue') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            preg_match_all('/<style\b[^>]*>(.*?)<\/style>/s', $source, $styles);

            foreach ($styles[1] as $style) {
                preg_match_all('/([^{}]+)\{([^{}]*)\}/', $style, $rules, PREG_SET_ORDER);

                foreach ($rules as [, $selector, $body]) {
                    if (preg_match('/background(?:-color)?\s*:\s*var\(--color-accent-fill\)/', $body) === 1) {
                        $fills++;
                    }

                    if (preg_match('/background(?:-color)?\s*:\s*var\(--color-accent\)/', $body) === 1
                        && str_contains($body, 'var(--color-on-accent)')) {
                        $found[] = substr($file->getPathname(), strlen($root) + 1).': '.trim($selector);
                    }
                }
            }
        }

        self::assertGreaterThanOrEqual(3, $fills, 'the primary button, the filter apply button and the checked box use the fill');
        self::assertSame([], $found, 'white on --color-accent instead of --color-accent-fill');
    }

    /**
     * Rejects: a hard-coded colour, rgba() shadow or gradient in a style
     * block of a `.vue` file (the Sidebar logo gradient and the shadows
     * moved to tokens), which keeps its light value in the dark scheme.
     * Still passes: a colour in a template attribute (the logo artwork and
     * the glyphs on a status colour stay fixed on purpose).
     */
    public function test_component_styles_take_every_colour_from_the_tokens(): void
    {
        $root = dirname(__DIR__, 2).'/resources/js';
        $found = [];
        $files = 0;

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'vue') {
                continue;
            }

            $files++;
            $source = (string) file_get_contents($file->getPathname());

            if (preg_match_all('/<style\b[^>]*>(.*?)<\/style>/s', $source, $styles) === 0) {
                continue;
            }

            foreach ($styles[1] as $style) {
                if (preg_match_all('/#[0-9a-fA-F]{3,8}\b|\b(?:rgba?|hsla?)\(|\b(?:linear|radial|conic)-gradient\(|\b(?:white|black)\b(?![-\w])/', $style, $matches) > 0) {
                    foreach ($matches[0] as $match) {
                        $found[] = substr($file->getPathname(), strlen($root) + 1).': '.$match;
                    }
                }
            }
        }

        self::assertGreaterThan(20, $files);
        self::assertSame([], $found, 'colours outside resources/css/tokens.css');
    }

    private function tokens(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/tokens.css');
    }

    /**
     * The first :root block, and the :root block inside the dark media query.
     *
     * @return array{string, string}
     */
    private function blocks(string $css): array
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        self::assertSame(1, preg_match('/^:root\s*\{([^}]*)\}/m', $css, $light), 'a top-level :root block');
        self::assertSame(1, preg_match('/@media\s*\(prefers-color-scheme:\s*dark\)\s*\{\s*:root\s*\{([^}]*)\}\s*\}/', $css, $dark), 'a :root block for prefers-color-scheme: dark');

        return [$light[1], $dark[1]];
    }

    /**
     * @return array<string, string>
     */
    private function declarations(string $block): array
    {
        preg_match_all('/(--[\w-]+)\s*:\s*([^;]+);/', $block, $matches, PREG_SET_ORDER);
        $declarations = [];

        foreach ($matches as [, $name, $value]) {
            $declarations[$name] = trim($value);
        }

        return $declarations;
    }

    private function contrast(string $a, string $b): float
    {
        $x = $this->luminance($a);
        $y = $this->luminance($b);

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    private function luminance(string $hex): float
    {
        self::assertMatchesRegularExpression('/^#[0-9a-fA-F]{6}$/', $hex);
        $channels = array_map(static function (string $pair): float {
            $value = hexdec($pair) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split(substr($hex, 1), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
