<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The static state of the package. State lives in
 * container bindings: a singleton for process state, a scoped binding for
 * the state of one unit of work. So a new application (a test, an Octane
 * worker's sandbox) starts clean without a reset, and a long-lived process
 * keeps exactly the state that its lifetime names.
 *
 * Every static property and every static local variable in src/ must be in
 * the allow-list below, with the decision that keeps it static.
 */
#[Group('scripts')]
final class StaticStateInventoryTest extends TestCase
{
    /** "file::$name" => why it stays static */
    private const ALLOWED = [
        // The public static API: the application's provider sets these before any request.
        'src/Orrery.php::$authUsing' => 'the public static API: Orrery::auth()',
        'src/Orrery.php::$operateUsing' => 'the public static API: Orrery::operateUsing()',
        'src/Orrery.php::$actorUsing' => 'the public static API: Orrery::actorUsing()',
        'src/Orrery.php::$email' => 'the public static API: a notification route',
        'src/Orrery.php::$slackWebhookUrl' => 'the public static API: a notification route',
        'src/Orrery.php::$slackChannel' => 'the public static API: a notification route',
        'src/Orrery.php::$smsNumber' => 'the public static API: a notification route',
        'src/Tags.php::$event' => 'the public static API: Tags, as Horizon has it',
        // Test seams.
        'src/Runtime/Hooks.php::$callback' => 'the hook point seam of the process tests',
        'src/Storage/Clock.php::$monotonicOffset' => 'the test clock seam of the monotonic clock',
        'src/Storage/Clock.php::$frozenMonotonic' => 'the test clock seam of the monotonic clock: its frozen base',
        'src/Storage/DatabaseClock.php::$offsetForTesting' => 'the test clock seam of the database clocks; a worker child sets it before its application boots',
        'src/Registration/Modules.php::$extra' => 'the test modules: a test adds them before the provider registers, so before any binding exists',
    ];

    /**
     * Rejects: new static state without a decision, for example a static
     * counter on JobStore, or a static cache that a later test or a later
     * Octane request would see.
     * Still passes: state kept in a global variable or in a constant array
     * of an object (no static keyword).
     */
    public function test_every_static_property_is_allowed(): void
    {
        $found = [];

        foreach (self::statics() as $key => $line) {
            if (! isset(self::ALLOWED[$key])) {
                $found[] = $key.' (line '.$line.')';
            }
        }

        self::assertSame([], $found, 'Static state without a decision: move it into a container binding (singleton or scoped), or add it to the allow-list with its reason.');
    }

    /**
     * Rejects: an allow-list that keeps a name whose static is gone.
     */
    public function test_every_allowed_entry_exists(): void
    {
        self::assertSame([], array_values(array_diff(array_keys(self::ALLOWED), array_keys(self::statics()))), 'The allow-list names a static that no longer exists.');
    }

    /**
     * Rejects: a scanner that misses one form of static declaration (also
     * the later names of `static int $a = 0, $b = 0;`), or that reads the
     * argument of `new static($x)` as a declaration.
     */
    public function test_scanner_finds_each_declaration_form(): void
    {
        $code = <<<'PHP'
            <?php
            class A {
                private static array $one = [];
                public static ?\Closure $two = null;
                static int|string $three = 0;
                protected readonly static int $four;
                public function f(): static { static $five = null; $g = static fn () => 1; return new static; }
                public static function h(): void { static::$one = []; }
                private static int $six = 0, $seven = 0;
                private static array $eight = [1, 2], $nine = [];
                public static function make($x): static { return new static($x); }
            }
            PHP;

        self::assertSame(['$one', '$two', '$three', '$four', '$five', '$six', '$seven', '$eight', '$nine'], array_keys(self::declarations($code)));
    }

    /**
     * Every static property and static local variable in src/, keyed
     * "file::$name", with its line.
     *
     * @return array<string, int>
     */
    private static function statics(): array
    {
        $root = dirname(__DIR__, 2);
        $statics = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src')) as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root) + 1);

            foreach (self::declarations((string) file_get_contents($file->getPathname())) as $name => $line) {
                $statics[$relative.'::'.$name] = $line;
            }
        }

        ksort($statics);

        return $statics;
    }

    /**
     * The variables that follow the keyword `static`, past its modifiers and
     * type: static properties and static local variables. `static fn`,
     * `static function`, `static::`, `new static` and the return type
     * `static` are no declarations.
     *
     * @return array<string, int>
     */
    private static function declarations(string $code): array
    {
        $tokens = token_get_all($code);
        $skip = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_READONLY, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_ARRAY, T_CALLABLE];
        $found = [];

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_STATIC || self::afterNew($tokens, $index)) {
                continue;
            }

            for ($next = $index + 1; isset($tokens[$next]); $next++) {
                $candidate = $tokens[$next];

                if (is_array($candidate) && $candidate[0] === T_VARIABLE) {
                    $found[$candidate[1]] = $candidate[2];

                    foreach (self::laterNames($tokens, $next + 1) as $name => $line) {
                        $found[$name] = $line;
                    }

                    break;
                }

                if (is_array($candidate) && in_array($candidate[0], $skip, true)) {
                    continue;
                }

                if (in_array($candidate, ['?', '|', '(', ')'], true) || (is_array($candidate) && $candidate[1] === '&')) {
                    continue;
                }

                break;
            }
        }

        return $found;
    }

    /**
     * Whether the token before $index (past whitespace) is `new`.
     *
     * @param  list<mixed>  $tokens
     */
    private static function afterNew(array $tokens, int $index): bool
    {
        for ($previous = $index - 1; $previous >= 0; $previous--) {
            $token = $tokens[$previous];

            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            return is_array($token) && $token[0] === T_NEW;
        }

        return false;
    }

    /**
     * The later names of one declaration statement: each variable after a
     * comma at bracket depth 0, until the semicolon (`static int $a = 0, $b`).
     * A static local variable statement has the same form.
     *
     * @param  list<mixed>  $tokens
     * @return array<string, int>
     */
    private static function laterNames(array $tokens, int $from): array
    {
        $names = [];
        $depth = 0;
        $afterComma = false;

        for ($next = $from; isset($tokens[$next]); $next++) {
            $token = $tokens[$next];
            $text = is_array($token) ? $token[1] : $token;

            if ($depth === 0 && $text === ';') {
                break;
            }

            if (in_array($text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($text, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($depth === 0 && $text === ',') {
                $afterComma = true;
            } elseif ($afterComma && is_array($token) && $token[0] === T_VARIABLE) {
                $names[$token[1]] = $token[2];
                $afterComma = false;
            } elseif (! (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT], true))) {
                $afterComma = false;
            }
        }

        return $names;
    }
}
