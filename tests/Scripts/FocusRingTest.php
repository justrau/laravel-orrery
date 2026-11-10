<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Every control is reachable by keyboard in a visible focus
 * order. A rule that removes the browser's focus outline (`outline: none`
 * or `outline: 0`) must put another ring in its place:
 *
 * - a rule on a focus state (`:focus`, `:focus-visible`) draws its own ring
 *   in the same rule (`box-shadow`, `border-color` or another `outline`), or
 *   only hides the ring of a mouse focus (`:not(:focus-visible)`);
 * - any other rule (an input inside a styled box) is listed in RINGS with
 *   the selector that draws the ring, and that selector has a rule with an
 *   `outline` or a `box-shadow` in the same file. A rule that needs no ring
 *   (a container that only a script focuses, never the Tab key) is listed
 *   with null and its reason.
 *
 * The test reads files only (group scripts: `composer test:all` runs it).
 */
#[Group('scripts')]
final class FocusRingTest extends TestCase
{
    /**
     * The rules that remove the outline outside a focus state, by file and
     * selector (whitespace collapsed), with the selector that draws their ring.
     *
     * @var array<string, array<string, string|null>>
     */
    private const RINGS = [
        'resources/js/components/ui/FilterBar.vue' => [
            '.o-filters__search input, .o-filters__chip input, .o-filters__chip select' => '.o-filters__search:focus-within, .o-filters__chip:focus-within',
        ],
        'resources/js/screens/batches/BatchesScreen.vue' => [
            '.o-batches__search-input' => '.o-batches__search:focus-within',
        ],
        'resources/js/screens/failed/index.vue' => [
            '.o-select__input' => '.o-select:focus-within',
        ],
        // The open history entry (tabindex="-1"): the screen focuses it after a click, the Tab key never does.
        'resources/js/screens/jobs/JobDetail.vue' => [
            '.o-jd__entry' => null,
        ],
    ];

    /**
     * Rejects: an input or select whose outline is removed while no rule
     * draws a ring for it (the Failure groups "Queue" select before this
     * test), a listed ring selector that no rule of the file styles or that
     * only sets `box-shadow: none`, a listed entry that no rule matches any
     * more, and a scan that reads no file.
     * Still passes: a ring that another rule hides again later (a higher
     * specificity `box-shadow: none`), and a ring colour with too little
     * contrast: the test reads the declarations, not the rendered page.
     */
    public function test_every_removed_outline_has_a_ring(): void
    {
        $files = self::styleFiles();
        self::assertGreaterThanOrEqual(30, count($files), 'The scan found too few style sources under resources/.');

        $problems = [];
        $listed = [];
        foreach ($files as $path => $css) {
            foreach (self::problems($path, $css, self::RINGS[$path] ?? []) as $problem) {
                $problems[] = $path.': '.$problem;
            }
            foreach (array_keys(self::RINGS[$path] ?? []) as $selector) {
                $listed[$path.' '.$selector] = true;
            }
        }

        foreach (self::RINGS as $path => $rings) {
            foreach (array_keys($rings) as $selector) {
                if (! isset($listed[$path.' '.$selector])) {
                    $problems[] = "{$path}: the listed file was not scanned";
                }
            }
        }

        self::assertSame([], $problems, "A control has no visible focus ring (D8 spec 5.5):\n".implode("\n", $problems));
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, string|null>, 2: list<string>}>
     */
    public static function cases(): iterable
    {
        yield 'an unlisted input without a ring' => [
            ".o-box { border: 1px solid; }\n.o-box__input { outline: none; }",
            [],
            ['.o-box__input removes the outline and no ring is listed for it'],
        ];
        yield 'a listed ring that no rule draws' => [
            ".o-box { border: 1px solid; }\n.o-box__input { outline: none; }",
            ['.o-box__input' => '.o-box:focus-within'],
            ['.o-box__input: no rule of the file draws its ring .o-box:focus-within'],
        ];
        yield 'a listed ring that draws nothing' => [
            ".o-box:focus-within { color: red; }\n.o-box__input { outline: 0; }",
            ['.o-box__input' => '.o-box:focus-within'],
            ['.o-box__input: no rule of the file draws its ring .o-box:focus-within'],
        ];
        yield 'a focus state without another ring' => [
            '.o-input:focus { outline: none; color: red; }',
            [],
            ['.o-input:focus removes the outline and draws no other ring'],
        ];
        yield 'a listed ring inside a media query' => [
            "@media (max-width: 767px) {\n    .o-box:focus-within { outline: 2px solid var(--color-accent); }\n}\n.o-box__input { outline: none; }",
            ['.o-box__input' => '.o-box:focus-within'],
            [],
        ];
        yield 'a listed ring that only removes a shadow' => [
            ".o-box:focus-within { box-shadow: none; }\n.o-box__input { outline: none; }",
            ['.o-box__input' => '.o-box:focus-within'],
            ['.o-box__input: no rule of the file draws its ring .o-box:focus-within'],
        ];
        yield 'a focus state whose only ring is no shadow' => [
            '.o-input:focus { box-shadow: none; outline: none; }',
            [],
            ['.o-input:focus removes the outline and draws no other ring'],
        ];
        yield 'a listed ring that only removes the outline' => [
            ".o-box:focus-within { outline: none; }\n.o-box__input { outline: none; }",
            ['.o-box__input' => '.o-box:focus-within'],
            ['.o-box:focus-within removes the outline and draws no other ring', '.o-box__input: no rule of the file draws its ring .o-box:focus-within'],
        ];
        yield 'a stale listed entry' => [
            ".o-box:focus-within { outline: 2px solid red; }\n.o-box__input { color: red; }",
            ['.o-box__input' => '.o-box:focus-within'],
            ['.o-box__input is listed, but no rule of the file removes its outline'],
        ];
        yield 'a focus state with its own ring' => [
            '.o-input:focus-visible { border-color: var(--color-accent); box-shadow: 0 0 0 3px red; outline: none; }',
            [],
            [],
        ];
        yield 'a mouse focus only' => [
            'button:focus:not(:focus-visible) { outline: 0; }',
            [],
            [],
        ];
        yield 'a container that needs no ring' => [
            '/* outline: none in a comment */ .o-panel { outline: none; }',
            ['.o-panel' => null],
            [],
        ];
        yield 'an outline that is not removed' => [
            '.o-a { outline: 2px solid red; outline-offset: 0; }',
            [],
            [],
        ];
    }

    /**
     * Rejects: a scan that misses one of the broken forms, or refuses one of
     * the allowed ones (each case is one small style sheet).
     *
     * @param  array<string, string|null>  $rings
     * @param  list<string>  $expected
     */
    #[DataProvider('cases')]
    public function test_each_case(string $css, array $rings, array $expected): void
    {
        self::assertSame($expected, self::problems('x.css', $css, $rings));
    }

    /**
     * @param  array<string, string|null>  $rings
     * @return list<string>
     */
    private static function problems(string $path, string $css, array $rings): array
    {
        $rules = self::rules($css);
        $problems = [];
        $removing = [];

        foreach ($rules as [$selector, $body]) {
            if (preg_match('/(^|;)\s*outline\s*:\s*(none|0)\s*(;|$)/i', $body) !== 1) {
                continue;
            }

            $removing[$selector] = true;

            if (str_contains($selector, ':focus')) {
                if (str_contains($selector, ':not(:focus-visible)')) {
                    continue;
                }
                if (preg_match('/(^|;)\s*(box-shadow\s*:\s*+(?!none\b)|border-color\s*:)/i', $body) !== 1) {
                    $problems[] = "{$selector} removes the outline and draws no other ring";
                }

                continue;
            }

            if (! array_key_exists($selector, $rings)) {
                $problems[] = "{$selector} removes the outline and no ring is listed for it";

                continue;
            }

            $ring = $rings[$selector];
            if ($ring === null) {
                continue;
            }

            $drawn = false;
            foreach ($rules as [$other, $otherBody]) {
                if ($other === $ring && preg_match('/(^|;)\s*(outline\s*:\s*+(?!none\b|0\b)[^;]+|box-shadow\s*:\s*+(?!none\b))/i', $otherBody) === 1) {
                    $drawn = true;
                }
            }
            if (! $drawn) {
                $problems[] = "{$selector}: no rule of the file draws its ring {$ring}";
            }
        }

        // A listed entry whose rule is gone or no longer removes the outline: the list must follow the code.
        foreach (array_keys($rings) as $selector) {
            if (! isset($removing[$selector])) {
                $problems[] = "{$selector} is listed, but no rule of the file removes its outline";
            }
        }

        return $problems;
    }

    /**
     * The innermost rules of a style sheet, as [selector, body] pairs, with
     * comments removed and whitespace collapsed. A rule inside a media query
     * counts as a rule (the regex cannot cross a brace).
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function rules(string $css): array
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER);

        $rules = [];
        foreach ($matches as $match) {
            $selector = trim((string) preg_replace('/\s+/', ' ', $match[1]));
            $selector = (string) preg_replace('/\s*,\s*/', ', ', $selector);
            $rules[] = [$selector, trim((string) preg_replace('/\s+/', ' ', $match[2]))];
        }

        return $rules;
    }

    /**
     * The style sources of the package: the `<style>` blocks of every `.vue`
     * file under resources/js and every `.css` and `.scss` file under
     * resources/, by path relative to the root.
     *
     * @return array<string, string>
     */
    private static function styleFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/resources', RecursiveDirectoryIterator::SKIP_DOTS));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $extension = $file->getExtension();
            if (! in_array($extension, ['vue', 'css', 'scss'], true)) {
                continue;
            }

            $path = substr($file->getPathname(), strlen($root) + 1);
            $source = (string) file_get_contents($file->getPathname());

            if ($extension === 'vue') {
                preg_match_all('/^<style\b[^>]*>(.*?)^<\/style>/ms', $source, $blocks);
                $source = implode("\n", $blocks[1]);
            }

            $files[$path] = $source;
        }

        ksort($files);

        return $files;
    }
}
