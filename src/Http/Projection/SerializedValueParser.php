<?php

namespace Justrau\Orrery\Http\Projection;

/**
 * The data-only parser of PHP's serialize format.
 *
 * It tokenises the text. It never calls unserialize(), never resolves or
 * autoloads a class (no class_exists(), no enum_exists()), and never runs
 * application code. It reads the input in place by offsets: a nested value
 * is parsed inside the same buffer, never copied.
 *
 * The output (Redactor::tree() turns it into plain JSON data):
 *
 * - `N;`, `b:`, `i:` give null, a bool, an int. An integer outside the
 *   signed 64-bit range is unsupported.
 * - `d:` gives a float; INF, -INF and NAN (also a literal that overflows,
 *   like `d:1e999;`) give the strings "INF", "-INF", "NAN", so
 *   json_encode() never fails.
 * - `s:<len>:"…";` gives the string (string rule, string()): a string that
 *   starts with a well-formed serialized prefix (PREFIX) is parsed as a
 *   nested value at its depth + 1 under the same budget, and gives
 *   ParsedNode::serialized(), or {"omitted": "nested_serialized", "bytes":
 *   n} when that parse fails for its form; a string over STRING_BYTES gives
 *   {"omitted": "string_too_large", "bytes": n}, never a prefix; invalid
 *   UTF-8 gives {"binary": n} (the byte count, never the bytes).
 * - `a:<n>:{…}` gives ParsedNode::array(); `O:<len>:"<class>":<n>:{…}`
 *   gives ParsedNode::object() with the logical property names
 *   (`\0Class\0prop` private, `\0*\0prop` protected, else public).
 * - An object of a closure class (CLOSURES, in any letter case) gives
 *   {"closure": true}, and so does the `closure` property of
 *   CallQueuedClosure, whatever its value. Their content is read only to
 *   find its end: its strings are never inspected, parsed or returned.
 * - `E:<len>:"<Class>:<Case>";` gives {"enum": "<Class>::<Case>"}.
 * - `C:<len>:"<class>":<len>:{…}` gives {"class", "custom": true, "bytes":
 *   n}; its content is not parsed.
 * - `r:<n>;` and `R:<n>;` give {"ref": n}, never expanded.
 *
 * Any other token, a length mismatch or trailing bytes throw
 * UnsupportedValue; a budget throws ProjectionLimit (ProjectionBudget).
 * project() turns both into {"unavailable": "unsupported"} and
 * {"unavailable": "limit", "limit": "<name>"}: a partial tree is never
 * returned. Neither exception carries an input byte in its message.
 *
 * Not final: the projection tests replace it with a throwing one.
 */
class SerializedValueParser
{
    /** The longest string that the output keeps. */
    public const STRING_BYTES = 65_536;

    /** The prefix of a string that holds a serialized value; `C:\temp` does not match. */
    private const PREFIX = '/\A(?:a:\d+:\{|O:\d+:"|C:\d+:"|E:\d+:"|s:\d+:"|i:-?\d+;|d:[^;]{1,64};|b:[01];|N;)/';

    /** The bytes of a string that PREFIX examines. */
    private const PREFIX_BYTES = 96;

    /** The lower-case names of the closure classes. */
    private const CLOSURES = [
        'laravel\\serializableclosure\\serializableclosure',
        'laravel\\serializableclosure\\serializers\\signed',
        'laravel\\serializableclosure\\serializers\\native',
        'laravel\\serializableclosure\\unsignedserializableclosure',
    ];

    private const CALL_QUEUED_CLOSURE = 'illuminate\\queue\\callqueuedclosure';

    private const NAME = '[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*';

    private const FLOAT = '/\A-?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?\z/';

    /** The most digits of a length, a count or a reference. */
    private const DIGITS = 18;

    /**
     * The value, or a marker when it is unsupported or over a budget.
     */
    public function project(string $input, ProjectionBudget $budget, int $depth = 0): mixed
    {
        try {
            return $this->value($input, $budget, $depth);
        } catch (ProjectionLimit $limit) {
            return $limit->marker();
        } catch (UnsupportedValue) {
            return ['unavailable' => 'unsupported'];
        }
    }

    /**
     * The value of a whole serialized text.
     *
     * @throws ProjectionLimit
     * @throws UnsupportedValue
     */
    public function value(string $input, ProjectionBudget $budget, int $depth = 0): mixed
    {
        $budget->input(strlen($input));

        return $this->range($input, 0, strlen($input), $budget, $depth);
    }

    /**
     * A string of job data under the string rule (a nested serialized
     * value, a large string, invalid UTF-8). The envelope uses it for each
     * of its strings.
     *
     * @throws ProjectionLimit
     */
    public function string(string $value, ProjectionBudget $budget, int $depth = 0): mixed
    {
        return $this->stringAt($value, 0, strlen($value), $budget, $depth);
    }

    /**
     * The command of a payload (`data.command`): a serialized value is
     * parsed; else a base64 JSON Laravel encrypter envelope (iv, value, mac,
     * optional tag) gives {"encrypted": true}, never decrypted; anything
     * else gives {"unavailable": "unknown_format"}.
     */
    public function command(mixed $command, ProjectionBudget $budget): mixed
    {
        if (! is_string($command)) {
            return ['unavailable' => 'unknown_format'];
        }

        if ($this->looksSerialized($command)) {
            return $this->project($command, $budget);
        }

        $decoded = base64_decode($command, true);
        $envelope = $decoded === false ? null : json_decode($decoded, true, 4);

        if (! is_array($envelope)) {
            return ['unavailable' => 'unknown_format'];
        }

        foreach ($envelope as $key => $part) {
            if (! in_array($key, ['iv', 'value', 'mac', 'tag'], true) || ! is_string($part)) {
                return ['unavailable' => 'unknown_format'];
            }
        }

        return isset($envelope['iv'], $envelope['value'], $envelope['mac']) ? ['encrypted' => true] : ['unavailable' => 'unknown_format'];
    }

    /**
     * Whether a string starts with a well-formed serialized prefix.
     */
    public function looksSerialized(string $value): bool
    {
        return $this->prefixAt($value, 0, strlen($value));
    }

    /**
     * @throws ProjectionLimit
     * @throws UnsupportedValue
     */
    private function range(string $buffer, int $start, int $end, ProjectionBudget $budget, int $depth): mixed
    {
        $budget->charge($end - $start);
        $at = $start;
        $value = $this->read($buffer, $at, $end, $budget, $depth, true);

        if ($at !== $end) {
            throw new UnsupportedValue('trailing bytes');
        }

        return $value;
    }

    private function prefixAt(string $buffer, int $start, int $length): bool
    {
        return $length >= 2 && preg_match(self::PREFIX, substr($buffer, $start, min($length, self::PREFIX_BYTES))) === 1;
    }

    /**
     * @throws ProjectionLimit
     */
    private function stringAt(string $buffer, int $start, int $length, ProjectionBudget $budget, int $depth): mixed
    {
        if ($this->prefixAt($buffer, $start, $length)) {
            try {
                return ParsedNode::serialized($this->range($buffer, $start, $start + $length, $budget, $depth + 1));
            } catch (UnsupportedValue) {
                return ['omitted' => 'nested_serialized', 'bytes' => $length];
            }
        }

        return $this->text($buffer, $start, $length);
    }

    /**
     * A string or key as output text: a marker when it is too large or not
     * UTF-8.
     *
     * @return string|array{omitted: string, bytes: int}|array{binary: int}
     */
    private function text(string $buffer, int $start, int $length): string|array
    {
        if ($length > self::STRING_BYTES) {
            return ['omitted' => 'string_too_large', 'bytes' => $length];
        }

        $text = substr($buffer, $start, $length);

        return mb_check_encoding($text, 'UTF-8') ? $text : ['binary' => $length];
    }

    /**
     * One value at $at. With $build false, the value is only skipped: its
     * strings are not inspected, and nothing is built or counted as a node.
     *
     * @throws ProjectionLimit
     * @throws UnsupportedValue
     */
    private function read(string $buffer, int &$at, int $end, ProjectionBudget $budget, int $depth, bool $build): mixed
    {
        $budget->enter($depth);

        if ($build) {
            $budget->node();
        }

        if ($at >= $end) {
            throw new UnsupportedValue('end of input');
        }

        switch ($buffer[$at]) {
            case 'N':
                $this->expect($buffer, $at, $end, 'N;');

                return null;

            case 'b':
                $this->expect($buffer, $at, $end, 'b:');
                $bit = $at < $end ? $buffer[$at] : '';

                if ($bit !== '0' && $bit !== '1') {
                    throw new UnsupportedValue('boolean');
                }

                $at++;
                $this->expect($buffer, $at, $end, ';');

                return $bit === '1';

            case 'i':
                $this->expect($buffer, $at, $end, 'i:');
                $integer = $this->integer($buffer, $at, $end);
                $this->expect($buffer, $at, $end, ';');

                return $integer;

            case 'd':
                $this->expect($buffer, $at, $end, 'd:');

                return $this->float($buffer, $at, $end);

            case 's':
                $this->expect($buffer, $at, $end, 's:');
                [$start, $length] = $this->quoted($buffer, $at, $end);
                $this->expect($buffer, $at, $end, ';');

                return $build ? $this->stringAt($buffer, $start, $length, $budget, $depth) : null;

            case 'a':
                return $this->array($buffer, $at, $end, $budget, $depth, $build);

            case 'O':
                return $this->object($buffer, $at, $end, $budget, $depth, $build);

            case 'E':
                $this->expect($buffer, $at, $end, 'E:');
                [$start, $length] = $this->quoted($buffer, $at, $end);
                $this->expect($buffer, $at, $end, ';');
                $enum = substr($buffer, $start, $length);

                if (preg_match('/\A('.self::NAME.'(?:\\\\'.self::NAME.')*):('.self::NAME.')\z/', $enum, $parts) !== 1 || ! mb_check_encoding($enum, 'UTF-8')) {
                    throw new UnsupportedValue('enum');
                }

                return ['enum' => $parts[1].'::'.$parts[2]];

            case 'C':
                $this->expect($buffer, $at, $end, 'C:');
                $class = $this->className($buffer, $at, $end);
                $this->expect($buffer, $at, $end, ':');
                $bytes = $this->digits($buffer, $at, $end);
                $this->expect($buffer, $at, $end, ':{');

                if ($bytes > $end - $at - 1) {
                    throw new UnsupportedValue('custom length');
                }

                $at += $bytes;
                $this->expect($buffer, $at, $end, '}');

                return ['class' => $class, 'custom' => true, 'bytes' => $bytes];

            case 'r':
            case 'R':
                $at++;
                $this->expect($buffer, $at, $end, ':');
                $reference = $this->digits($buffer, $at, $end);
                $this->expect($buffer, $at, $end, ';');

                return ['ref' => $reference];

            default:
                throw new UnsupportedValue('token');
        }
    }

    /**
     * @throws ProjectionLimit
     * @throws UnsupportedValue
     */
    private function array(string $buffer, int &$at, int $end, ProjectionBudget $budget, int $depth, bool $build): ?ParsedNode
    {
        $this->expect($buffer, $at, $end, 'a:');
        $count = $this->count($buffer, $at, $end);
        $this->expect($buffer, $at, $end, ':{');

        if ($build) {
            $budget->declare($count);
        }

        $entries = [];

        for ($index = 0; $index < $count; $index++) {
            $key = $this->key($buffer, $at, $end, $build);
            $value = $this->read($buffer, $at, $end, $budget, $depth + 1, $build);

            if ($build) {
                $entries[] = new ParsedEntry($key, $value);
            }
        }

        $this->expect($buffer, $at, $end, '}');

        return $build ? ParsedNode::array($entries) : null;
    }

    /**
     * @return ParsedNode|array{closure: true}|null
     *
     * @throws ProjectionLimit
     * @throws UnsupportedValue
     */
    private function object(string $buffer, int &$at, int $end, ProjectionBudget $budget, int $depth, bool $build): ParsedNode|array|null
    {
        $this->expect($buffer, $at, $end, 'O:');
        $class = $this->className($buffer, $at, $end);
        $this->expect($buffer, $at, $end, ':');
        $count = $this->count($buffer, $at, $end);
        $this->expect($buffer, $at, $end, ':{');
        $lower = strtolower($class);
        $closure = in_array($lower, self::CLOSURES, true);
        $build = $build && ! $closure;

        if ($build) {
            $budget->declare($count);
        }

        $properties = [];

        for ($index = 0; $index < $count; $index++) {
            [$name, $visibility, $declaredIn] = $this->property($buffer, $at, $end, $build);

            if ($build && $lower === self::CALL_QUEUED_CLOSURE && $name === 'closure') {
                // The closure property: read to its end, never inspected.
                $this->read($buffer, $at, $end, $budget, $depth + 1, false);
                $properties[] = new ParsedEntry($name, ['closure' => true], $visibility, $declaredIn);

                continue;
            }

            $value = $this->read($buffer, $at, $end, $budget, $depth + 1, $build);

            if ($build) {
                $properties[] = new ParsedEntry($name, $value, $visibility, $declaredIn);
            }
        }

        $this->expect($buffer, $at, $end, '}');

        if ($closure) {
            return ['closure' => true];
        }

        return $build ? ParsedNode::object($class, $properties) : null;
    }

    /**
     * An array key: an `i:` or `s:` token.
     *
     * @return int|string|array<string, int|string>
     *
     * @throws UnsupportedValue
     */
    private function key(string $buffer, int &$at, int $end, bool $build): int|string|array
    {
        if ($at < $end && $buffer[$at] === 'i') {
            $this->expect($buffer, $at, $end, 'i:');
            $key = $this->integer($buffer, $at, $end);
            $this->expect($buffer, $at, $end, ';');

            return $key;
        }

        $this->expect($buffer, $at, $end, 's:');
        [$start, $length] = $this->quoted($buffer, $at, $end);
        $this->expect($buffer, $at, $end, ';');

        return $build ? $this->text($buffer, $start, $length) : '';
    }

    /**
     * A property key: its logical name, visibility and declaring class.
     *
     * @return array{0: int|string|array<string, int|string>, 1: string, 2: string|null}
     *
     * @throws UnsupportedValue
     */
    private function property(string $buffer, int &$at, int $end, bool $build): array
    {
        if ($at < $end && $buffer[$at] === 'i') {
            return [$this->key($buffer, $at, $end, $build), ParsedEntry::PUBLIC, null];
        }

        $this->expect($buffer, $at, $end, 's:');
        [$start, $length] = $this->quoted($buffer, $at, $end);
        $this->expect($buffer, $at, $end, ';');

        if ($length === 0 || $buffer[$start] !== "\0") {
            return [$build ? $this->text($buffer, $start, $length) : '', ParsedEntry::PUBLIC, null];
        }

        $second = strpos($buffer, "\0", $start + 1);

        if ($second === false || $second >= $start + $length || $second === $start + 1) {
            throw new UnsupportedValue('property name');
        }

        $declaredIn = substr($buffer, $start + 1, $second - $start - 1);
        $name = $build ? $this->text($buffer, $second + 1, $start + $length - $second - 1) : '';

        if ($declaredIn === '*') {
            return [$name, ParsedEntry::PROTECTED, null];
        }

        if (! mb_check_encoding($declaredIn, 'UTF-8') || preg_match('/\A'.self::NAME.'(?:\\\\'.self::NAME.')*\z/', $declaredIn) !== 1) {
            throw new UnsupportedValue('property class');
        }

        return [$name, ParsedEntry::PRIVATE, $declaredIn];
    }

    /**
     * `<len>:"<class>"` of an object or a custom value.
     *
     * @throws UnsupportedValue
     */
    private function className(string $buffer, int &$at, int $end): string
    {
        [$start, $length] = $this->quoted($buffer, $at, $end);
        $class = substr($buffer, $start, $length);

        if (preg_match('/\A'.self::NAME.'(?:\\\\'.self::NAME.')*\z/', $class) !== 1 || ! mb_check_encoding($class, 'UTF-8')) {
            throw new UnsupportedValue('class name');
        }

        return $class;
    }

    /**
     * `<len>:"<bytes>"`: the start and length of the bytes, after the
     * closing quote.
     *
     * @return array{0: int, 1: int}
     *
     * @throws UnsupportedValue
     */
    private function quoted(string $buffer, int &$at, int $end): array
    {
        $length = $this->digits($buffer, $at, $end);
        $this->expect($buffer, $at, $end, ':"');

        if ($length > $end - $at - 1) {
            throw new UnsupportedValue('length');
        }

        $start = $at;
        $at += $length;
        $this->expect($buffer, $at, $end, '"');

        return [$start, $length];
    }

    /**
     * A declared count. A count with more digits than DIGITS is larger than
     * any budget: PHP_INT_MAX, so the node budget refuses it.
     *
     * @throws UnsupportedValue
     */
    private function count(string $buffer, int &$at, int $end): int
    {
        $digits = strspn($buffer, '0123456789', $at, min(64, $end - $at));

        if ($digits === 0) {
            throw new UnsupportedValue('count');
        }

        $text = substr($buffer, $at, $digits);
        $at += $digits;

        return $digits > self::DIGITS ? PHP_INT_MAX : (int) $text;
    }

    /**
     * A length or reference: 1 to DIGITS digits.
     *
     * @throws UnsupportedValue
     */
    private function digits(string $buffer, int &$at, int $end): int
    {
        $digits = strspn($buffer, '0123456789', $at, min(self::DIGITS + 1, $end - $at));

        if ($digits === 0 || $digits > self::DIGITS) {
            throw new UnsupportedValue('number');
        }

        $value = (int) substr($buffer, $at, $digits);
        $at += $digits;

        return $value;
    }

    /**
     * A signed decimal integer within the signed 64-bit range.
     *
     * @throws UnsupportedValue
     */
    private function integer(string $buffer, int &$at, int $end): int
    {
        $negative = $at < $end && $buffer[$at] === '-';
        $start = $negative ? $at + 1 : $at;
        $digits = $start < $end ? strspn($buffer, '0123456789', $start, min(64, $end - $start)) : 0;

        if ($digits === 0 || $digits >= 64) {
            throw new UnsupportedValue('integer');
        }

        $text = ltrim(substr($buffer, $start, $digits), '0');
        $at = $start + $digits;
        $limit = $negative ? '9223372036854775808' : '9223372036854775807';

        if (strlen($text) > 19 || (strlen($text) === 19 && strcmp($text, $limit) > 0)) {
            throw new UnsupportedValue('integer range');
        }

        if ($negative && $text === '9223372036854775808') {
            return PHP_INT_MIN;
        }

        return $negative ? -(int) $text : (int) $text;
    }

    /**
     * `<float>;`: a finite float, or "INF", "-INF", "NAN".
     *
     * @throws UnsupportedValue
     */
    private function float(string $buffer, int &$at, int $end): float|string
    {
        $length = strcspn($buffer, ';', $at, min(65, $end - $at));

        if ($length === 0 || $length > 64 || $at + $length >= $end) {
            throw new UnsupportedValue('float');
        }

        $text = substr($buffer, $at, $length);
        $at += $length + 1;

        if (in_array($text, ['INF', '-INF', 'NAN'], true)) {
            return $text;
        }

        if (preg_match(self::FLOAT, $text) !== 1) {
            throw new UnsupportedValue('float');
        }

        $float = (float) $text;

        if (is_nan($float)) {
            return 'NAN';
        }

        if (is_infinite($float)) {
            return $float > 0 ? 'INF' : '-INF';
        }

        return $float;
    }

    /**
     * @throws UnsupportedValue
     */
    private function expect(string $buffer, int &$at, int $end, string $literal): void
    {
        $length = strlen($literal);

        if ($at < 0 || $at + $length > $end || substr_compare($buffer, $literal, $at, $length) !== 0) {
            throw new UnsupportedValue('syntax');
        }

        $at += $length;
    }
}
