<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Projection;

use Justrau\Orrery\Http\Projection\ParsedNode;
use Justrau\Orrery\Http\Projection\ProjectionBudget;
use Justrau\Orrery\Http\Projection\ProjectionLimit;
use Justrau\Orrery\Http\Projection\SerializedValueParser;
use Justrau\Orrery\Http\Projection\UnsupportedValue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * The data-only serialized-value parser: it
 * tokenises PHP's serialize format, never calls unserialize(), never
 * resolves a class and never runs application code.
 *
 * Every test runs with a sentinel autoloader that fails the test on any
 * call (setUp()).
 */
final class SerializedValueParserTest extends TestCase
{
    /** @var list<string> the classes that an autoloader was asked for */
    private array $autoloaded = [];

    private ?\Closure $sentinel = null;

    protected function setUp(): void
    {
        parent::setUp();

        // The package's own classes load first; every later autoload call is a failure.
        foreach ([SerializedValueParser::class, ParsedNode::class, \Justrau\Orrery\Http\Projection\ParsedEntry::class, ProjectionBudget::class, ProjectionLimit::class, UnsupportedValue::class] as $class) {
            class_exists($class);
        }

        $this->autoloaded = [];
        $this->sentinel = function (string $class): void {
            $this->autoloaded[] = $class;
        };
        spl_autoload_register($this->sentinel, true, true);
    }

    protected function tearDown(): void
    {
        if ($this->sentinel !== null) {
            spl_autoload_unregister($this->sentinel);
        }

        self::assertSame([], $this->autoloaded, 'The parser asked an autoloader for a class.');

        parent::tearDown();
    }

    /**
     * Rejects: a parser that maps a scalar token to another type, or reads
     * a float through unserialize().
     *
     * @return array<string, array{string, mixed}>
     */
    public static function scalars(): array
    {
        return [
            'null' => ['N;', null],
            'true' => ['b:1;', true],
            'false' => ['b:0;', false],
            'integer' => ['i:42;', 42],
            'negative integer' => ['i:-7;', -7],
            'int64 max' => ['i:9223372036854775807;', PHP_INT_MAX],
            'int64 min' => ['i:-9223372036854775808;', PHP_INT_MIN],
            'float' => ['d:0.5;', 0.5],
            'exponent float' => ['d:1.0E+25;', 1.0E+25],
            'negative float' => ['d:-1.5E-3;', -0.0015],
            'INF' => ['d:INF;', 'INF'],
            'negative INF' => ['d:-INF;', '-INF'],
            'NAN' => ['d:NAN;', 'NAN'],
            'overflowing float' => ['d:1e999;', 'INF'],
            'string' => ['s:5:"hello";', 'hello'],
            'empty string' => ['s:0:"";', ''],
            'multibyte string' => ['s:5:"ünï";', 'ünï'],
        ];
    }

    #[DataProvider('scalars')]
    public function test_scalar_tokens(string $input, mixed $expected): void
    {
        self::assertSame($expected, $this->project($input));
    }

    /**
     * Rejects: json_encode() of the projection failing on a non-finite
     * float (d:1e999; is INF in PHP).
     */
    public function test_non_finite_floats_encode_as_strings(): void
    {
        $tree = (new SerializedValueParser)->project('a:3:{i:0;d:1e999;i:1;d:-1e999;i:2;d:NAN;}', new ProjectionBudget);

        self::assertSame('{"array":[{"key":0,"value":"INF"},{"key":1,"value":"-INF"},{"key":2,"value":"NAN"}]}', json_encode($tree, JSON_THROW_ON_ERROR));
    }

    /**
     * Rejects: a wrapped integer, a float for an integer, or a partial tree
     * with only the overflowing entry left out.
     *
     * @return array<string, array{string}>
     */
    public static function unsupported(): array
    {
        return [
            'int64 max + 1' => ['i:9223372036854775808;'],
            'int64 min - 1' => ['i:-9223372036854775809;'],
            'a long integer' => ['i:123456789012345678901234567890;'],
            'overflow inside an array' => ['a:2:{i:0;i:1;i:1;i:99999999999999999999;}'],
            'an empty integer' => ['i:;'],
            'a plus sign' => ['i:+5;'],
            'a float written as an integer' => ['i:1.5;'],
            'a malformed float' => ['d:1.2.3;'],
            'a float without digits' => ['d:;'],
            'a boolean 2' => ['b:2;'],
            'an unknown token' => ['X:1;'],
            'the object-reference letter in lower case' => ['o:8:"stdClass":0:{}'],
            'a string length lie (too long)' => ['s:10:"abc";'],
            'a string length lie (too short)' => ['s:2:"abc";'],
            'a string without its closing quote' => ['s:3:"abc;'],
            'a string length beyond the input' => ['s:999999999999:"abc";'],
            'a negative string length' => ['s:-1:"";'],
            'trailing bytes' => ['i:1;i:2;'],
            'a trailing space' => ['N; '],
            'an empty input' => [''],
            'an array count lie (too few)' => ['a:2:{i:0;i:1;}'],
            'an array count lie (too many)' => ['a:1:{i:0;i:1;i:1;i:2;}'],
            'an array without its brace' => ['a:1:{i:0;i:1;'],
            'an array key that is an array' => ['a:1:{a:0:{}i:1;}'],
            'an array key that is null' => ['a:1:{N;i:1;}'],
            'an object with a bad class name' => ['O:3:"1ab":0:{}'],
            'an object class length lie' => ['O:5:"stdClass":0:{}'],
            'a malformed private name' => ["O:8:\"stdClass\":1:{s:4:\"\0abc\";i:1;}"],
            'an enum without a case' => ['E:3:"Foo";'],
            'an enum with a bad case' => ['E:8:"Foo:1bar";'],
            'a custom value length lie' => ['C:3:"Foo":10:{abc}'],
            'a reference without digits' => ['r:;'],
            'a truncated input' => ['a:1:{i:0;s:5:"he'],
        ];
    }

    #[DataProvider('unsupported')]
    public function test_unsupported_input_gives_no_partial_tree(string $input): void
    {
        self::assertSame(['unavailable' => 'unsupported'], $this->project($input));
    }

    /**
     * Rejects: an array decoded as a PHP array (keys lost) or entries out of
     * their serialized order.
     */
    public function test_arrays_keep_their_keys_and_order(): void
    {
        self::assertSame(
            ['array' => [
                ['key' => 'b', 'value' => 1],
                ['key' => 0, 'value' => ['array' => [['key' => 0, 'value' => true]]]],
                ['key' => 'a', 'value' => null],
            ]],
            $this->project(serialize(['b' => 1, 0 => [true], 'a' => null])),
        );
    }

    /**
     * Rejects: mangled names returned raw, a lost declaring class, or
     * duplicate logical names merged into one entry.
     */
    public function test_object_properties_have_their_logical_names_and_visibility(): void
    {
        $input = 'O:13:"App\\Jobs\\Sync":4:{'
            ."s:4:\"name\";s:1:\"n\";"
            ."s:6:\"\0*\0key\";i:1;"
            ."s:21:\"\0App\\Jobs\\Sync\0secret\";s:1:\"x\";"
            ."s:21:\"\0App\\Jobs\\Base\0secret\";s:1:\"y\";"
            .'}';

        self::assertSame(
            ['class' => 'App\\Jobs\\Sync', 'properties' => [
                ['name' => 'name', 'visibility' => 'public', 'value' => 'n'],
                ['name' => 'key', 'visibility' => 'protected', 'value' => 1],
                ['name' => 'secret', 'visibility' => 'private', 'declared_in' => 'App\\Jobs\\Sync', 'value' => 'x'],
                ['name' => 'secret', 'visibility' => 'private', 'declared_in' => 'App\\Jobs\\Base', 'value' => 'y'],
            ]],
            $this->project($input),
        );
    }

    /**
     * Enum, object and custom tokens of classes that do not exist: the
     * sentinel autoloader proves that no class is resolved.
     *
     * Rejects: a parser that calls class_exists(), enum_exists() or
     * unserialize() on a class token.
     */
    public function test_tokens_of_missing_classes_resolve_nothing(): void
    {
        self::assertSame(['enum' => 'Missing\\Status::Active'], $this->project('E:21:"Missing\\Status:Active";'));
        self::assertSame(['class' => 'Missing\\Thing', 'properties' => [['name' => 'a', 'visibility' => 'public', 'value' => 1]]], $this->project('O:13:"Missing\\Thing":1:{s:1:"a";i:1;}'));
        self::assertSame(['class' => 'Missing\\Custom', 'custom' => true, 'bytes' => 10], $this->project('C:14:"Missing\\Custom":10:{s:3:"abc";}'));
    }

    /**
     * Rejects: references expanded to the referenced value (exponential
     * output) or resolved to a parse position.
     */
    public function test_references_are_never_expanded(): void
    {
        $self = [];
        $self['me'] = &$self;
        $object = new \stdClass;
        $object->self = $object;

        self::assertSame(['array' => [['key' => 'a', 'value' => 1], ['key' => 'b', 'value' => ['ref' => 2]]]], $this->project('a:2:{s:1:"a";i:1;s:1:"b";R:2;}'));
        self::assertSame(['class' => 'stdClass', 'properties' => [['name' => 'self', 'visibility' => 'public', 'value' => ['ref' => 1]]]], $this->project(serialize($object)));
        self::assertStringContainsString('"ref"', (string) json_encode($this->project(serialize($self))));
    }

    /**
     * Rejects: a closure's captured variables or inner serialized text in
     * the output (each closure class, in any letter case, and the closure
     * property of CallQueuedClosure, whatever its value).
     */
    public function test_closures_are_opaque(): void
    {
        $inner = serialize(['use' => ['password' => 'canary-closure-secret'], 'function' => 'function () use ($password) {}']);

        foreach ([
            'Laravel\\SerializableClosure\\SerializableClosure',
            'Laravel\\SerializableClosure\\Serializers\\Signed',
            'Laravel\\SerializableClosure\\Serializers\\Native',
            'Laravel\\SerializableClosure\\UnsignedSerializableClosure',
            'laravel\\serializableclosure\\serializableclosure',
        ] as $class) {
            $input = 'O:'.strlen($class).':"'.$class.'":2:{s:12:"serializable";s:'.strlen($inner).':"'.$inner.'";s:4:"hash";s:3:"abc";}';
            $output = (string) json_encode($this->project($input));

            self::assertSame('{"closure":true}', $output, $class);
        }

        $queued = 'O:34:"Illuminate\\Queue\\CallQueuedClosure":2:{s:7:"closure";s:'.strlen($inner).':"'.$inner.'";s:5:"queue";s:4:"high";}';
        self::assertSame(
            ['class' => 'Illuminate\\Queue\\CallQueuedClosure', 'properties' => [
                ['name' => 'closure', 'visibility' => 'public', 'value' => ['closure' => true]],
                ['name' => 'queue', 'visibility' => 'public', 'value' => 'high'],
            ]],
            $this->project($queued),
        );
    }

    /**
     * Rejects: invalid UTF-8 returned as bytes (json_encode() fails), or a
     * large string returned as a prefix.
     */
    public function test_binary_and_large_strings_are_markers(): void
    {
        self::assertSame(['binary' => 2], $this->project("s:2:\"\xff\xfe\";"));

        $large = str_repeat('a', 65537);
        self::assertSame(['omitted' => 'string_too_large', 'bytes' => 65537], $this->project(serialize($large)));
        self::assertSame(str_repeat('a', 65536), $this->project(serialize(str_repeat('a', 65536))));
    }

    /**
     * A string that holds a serialized value is parsed as a nested value;
     * a nested value that does not parse is omitted whole; a Windows path is
     * a plain string.
     *
     * Rejects: serialized text returned as a string (the browser would see
     * raw serialized PHP), or a failed nested parse failing the whole value.
     */
    public function test_nested_serialized_strings(): void
    {
        self::assertSame(['serialized' => ['array' => [['key' => 'k', 'value' => 'v']]]], $this->project(serialize(serialize(['k' => 'v']))));
        self::assertSame(['array' => [['key' => 0, 'value' => ['omitted' => 'nested_serialized', 'bytes' => 9]]]], $this->project(serialize(['a:1:{oops'])));
        self::assertSame('C:\\temp\\file.txt', $this->project(serialize('C:\\temp\\file.txt')));
        self::assertSame('d:', $this->project(serialize('d:')));
        self::assertSame(['serialized' => 5], $this->project(serialize('i:5;')));
    }

    /**
     * Rejects: a nested value that starts at depth 0 again (the depth would
     * not bound the nesting).
     */
    public function test_nested_values_count_their_string_depth(): void
    {
        $value = 'N;';

        for ($level = 0; $level < 40; $level++) {
            $value = serialize($value);
        }

        self::assertSame(['unavailable' => 'limit', 'limit' => 'depth'], $this->project($value));

        $array = 'N;';

        for ($level = 0; $level < 33; $level++) {
            $array = 'a:1:{i:0;'.$array.'}';
        }

        self::assertSame(['unavailable' => 'limit', 'limit' => 'depth'], $this->project($array));
        self::assertNotSame(['unavailable' => 'limit', 'limit' => 'depth'], $this->project(substr($array, 9, -1)), '32 levels parse');
    }

    /**
     * Rejects: a declared count used to pre-allocate (memory), or a count
     * above the node budget read entry by entry.
     */
    public function test_a_huge_declared_count_allocates_nothing(): void
    {
        $before = memory_get_usage();
        $started = hrtime(true);

        self::assertSame(['unavailable' => 'limit', 'limit' => 'nodes'], $this->project('a:1000000000:{i:0;i:1;}'));
        self::assertSame(['unavailable' => 'limit', 'limit' => 'nodes'], $this->project('O:8:"stdClass":1000000000:{s:1:"a";i:1;}'));
        self::assertSame(['unavailable' => 'limit', 'limit' => 'nodes'], $this->project('a:99999999999999999999999999:{}'));

        self::assertLessThan(1_000_000, memory_get_usage() - $before, 'No allocation for the declared count.');
        self::assertLessThan(1.0, (hrtime(true) - $started) / 1e9);
    }

    /**
     * Rejects: a node budget that does not count nested values.
     */
    public function test_the_node_budget(): void
    {
        $budget = new ProjectionBudget(maxNodes: 10);
        $parser = new SerializedValueParser;

        self::assertSame(['unavailable' => 'limit', 'limit' => 'nodes'], $parser->project(serialize(range(1, 10)), $budget));
        self::assertSame(['unavailable' => 'limit', 'limit' => 'nodes'], $parser->project(serialize([serialize(range(1, 5)), serialize(range(1, 5))]), new ProjectionBudget(maxNodes: 10)));
        self::assertInstanceOf(ParsedNode::class, $parser->project(serialize(range(1, 8)), new ProjectionBudget(maxNodes: 10)), 'An array node and its 8 values fit 10 nodes.');
    }

    /**
     * Rejects: an input bound checked after parsing (or never).
     */
    public function test_input_over_one_megabyte_is_refused_before_parsing(): void
    {
        $input = serialize(str_repeat('x', ProjectionBudget::INPUT_BYTES));

        self::assertGreaterThan(ProjectionBudget::INPUT_BYTES, strlen($input));
        self::assertSame(['unavailable' => 'limit', 'limit' => 'input'], $this->project($input));
    }

    /**
     * A 1 MB value whose string nests a serialized string 10,000 deep (N-2).
     *
     * Rejects: nested parses that copy or rescan their string per level
     * (quadratic work), or a work budget per parse instead of per
     * projection.
     */
    public function test_deep_nesting_in_one_megabyte_answers_within_the_work_budget(): void
    {
        $value = self::nested(str_repeat('x', 900_000), 10_000);

        self::assertSame(serialize(serialize('x')), self::nested('x', 2), 'The builder gives the serialize() form.');
        self::assertLessThanOrEqual(ProjectionBudget::INPUT_BYTES, strlen($value));
        $started = hrtime(true);
        $before = memory_get_peak_usage();

        $result = $this->project($value);

        self::assertSame('limit', $result['unavailable'] ?? null);
        self::assertContains($result['limit'] ?? null, ['work', 'depth']);
        self::assertLessThan(2.0, (hrtime(true) - $started) / 1e9);
        self::assertLessThan(16_000_000, memory_get_peak_usage() - $before);

        // Without the depth limit, the work limit ends it after about two levels.
        $budget = new ProjectionBudget(depth: 100_000);
        $started = hrtime(true);
        self::assertSame(['unavailable' => 'limit', 'limit' => 'work'], (new SerializedValueParser)->project($value, $budget));
        self::assertLessThanOrEqual(ProjectionBudget::WORK_BYTES + strlen($value), $budget->work(), 'The parse stopped at the first charge past the budget.');
        self::assertLessThan(2.0, (hrtime(true) - $started) / 1e9);
    }

    /**
     * Rejects: a work budget that charges only the outer parse.
     */
    public function test_the_work_budget_counts_nested_parses(): void
    {
        $inner = serialize(str_repeat('y', 100));
        $outer = serialize([$inner, $inner]);
        $parser = new SerializedValueParser;

        // The outer parse and one nested parse fit; the second nested parse does not.
        self::assertSame(['unavailable' => 'limit', 'limit' => 'work'], $parser->project($outer, new ProjectionBudget(workBytes: strlen($outer) + strlen($inner))));
        self::assertInstanceOf(ParsedNode::class, $parser->project($outer, new ProjectionBudget(workBytes: strlen($outer) + 2 * strlen($inner))));
    }

    /**
     * Rejects: an exception of the parser that carries input bytes.
     */
    public function test_parser_exceptions_carry_no_input_bytes(): void
    {
        $parser = new SerializedValueParser;
        $canary = 'canary-parser-secret';

        foreach ([
            's:99:"'.$canary.'";',
            'i:'.str_repeat('9', 30).';',
            'a:1:{s:20:"'.$canary.'";X;}',
            'O:20:"'.$canary.'":0:{}x',
            'Q'.$canary,
        ] as $input) {
            try {
                $parser->value($input, new ProjectionBudget);
                self::fail('The input must not parse.');
            } catch (UnsupportedValue|ProjectionLimit $error) {
                self::assertStringNotContainsString($canary, $error->getMessage());
                self::assertStringNotContainsString('999999', $error->getMessage());
            }
        }
    }

    /**
     * Fuzz: random mutations of real serialized values (byte changes,
     * truncation, length digits, inserted tokens).
     *
     * Rejects: a parser that throws on malformed input, returns a value
     * that json_encode() refuses, or takes more than linear time.
     */
    public function test_fuzzed_input_never_throws(): void
    {
        mt_srand(20261108);
        $seeds = [
            serialize(['a' => 1, 'b' => [true, null, 1.5, 'x'], 'c' => new \stdClass]),
            serialize([serialize(['nested' => 'value']), 'C:\\temp']),
            'O:13:"App\\Jobs\\Sync":2:{s:6:"'."\0*\0".'key";i:1;s:21:"'."\0App\\Jobs\\Sync\0".'secret";s:1:"x";}',
            'a:2:{s:1:"a";E:15:"App\\Status:Done";s:1:"b";R:2;}',
            'C:14:"Missing\\Custom":10:{s:3:"abc";}',
        ];
        $alphabet = 'aOsdibNCErR:;{}"0123456789-.\\'."\0\xff";
        $parser = new SerializedValueParser;
        $started = hrtime(true);

        for ($round = 0; $round < 3000; $round++) {
            $input = $seeds[$round % count($seeds)];

            for ($change = mt_rand(1, 4); $change > 0; $change--) {
                $at = mt_rand(0, max(0, strlen($input) - 1));
                $input = match (mt_rand(0, 4)) {
                    0 => substr_replace($input, $alphabet[mt_rand(0, strlen($alphabet) - 1)], $at, 1),
                    1 => substr($input, 0, $at),
                    2 => substr_replace($input, (string) mt_rand(0, PHP_INT_MAX), $at, 0),
                    3 => substr_replace($input, 'a:1000000:{', $at, 0),
                    default => substr_replace($input, 'R:'.mt_rand(0, 99).';', $at, 0),
                };
            }

            try {
                $result = $parser->project($input, new ProjectionBudget);
            } catch (Throwable $error) {
                self::fail('The parser threw '.$error::class.' on round '.$round.'.');
            }

            self::assertNotFalse(json_encode($result), 'Round '.$round.' encodes.');
        }

        self::assertLessThan(10.0, (hrtime(true) - $started) / 1e9);
    }

    /**
     * Rejects: a parser that decrypts, or treats any base64 JSON as an
     * encrypted command.
     */
    public function test_commands_that_are_not_serialized(): void
    {
        $parser = new SerializedValueParser;
        $envelope = base64_encode((string) json_encode(['iv' => 'aXY=', 'value' => 'dmFsdWU=', 'mac' => 'abc', 'tag' => '']));

        self::assertSame(['encrypted' => true], $parser->command($envelope, new ProjectionBudget));
        self::assertSame(['encrypted' => true], $parser->command(base64_encode((string) json_encode(['iv' => 'a', 'value' => 'b', 'mac' => 'c'])), new ProjectionBudget));
        self::assertSame(['unavailable' => 'unknown_format'], $parser->command(base64_encode((string) json_encode(['iv' => 'a', 'value' => 'b'])), new ProjectionBudget));
        self::assertSame(['unavailable' => 'unknown_format'], $parser->command(base64_encode((string) json_encode(['iv' => 'a', 'value' => 'b', 'mac' => 'c', 'extra' => 1])), new ProjectionBudget));
        self::assertSame(['unavailable' => 'unknown_format'], $parser->command('plain text', new ProjectionBudget));
        self::assertSame(['unavailable' => 'unknown_format'], $parser->command(['not' => 'a string'], new ProjectionBudget));
        self::assertSame(['unavailable' => 'unsupported'], $parser->command('a:1:{i:0;', new ProjectionBudget));
        self::assertInstanceOf(ParsedNode::class, $parser->command(serialize(['a' => 1]), new ProjectionBudget));
    }

    /**
     * serialize() applied $levels times, built without copying the value
     * once per level.
     */
    private static function nested(string $value, int $levels): string
    {
        $length = strlen($value);
        $prefixes = [];

        for ($level = 0; $level < $levels; $level++) {
            $prefixes[] = 's:'.$length.':"';
            $length += strlen((string) $length) + 6;
        }

        return implode('', array_reverse($prefixes)).$value.str_repeat('";', $levels);
    }

    private function project(string $input): mixed
    {
        $tree = (new SerializedValueParser)->project($input, new ProjectionBudget);

        return json_decode((string) json_encode($tree, JSON_PRESERVE_ZERO_FRACTION), true, 512, JSON_THROW_ON_ERROR);
    }
}
