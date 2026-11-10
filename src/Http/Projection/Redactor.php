<?php

namespace Justrau\Orrery\Http\Projection;

use Illuminate\Container\Container;
use Justrau\Orrery\Runtime\Watcher;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * The redaction of job data.
 *
 * A singleton. Its compiled rules are instance state, cached by a hash of
 * the `orrery.redact` configuration, which it reads from the running
 * container at each use: an Octane request with another configuration
 * recompiles.
 *
 * The configuration: `keys` and `patterns` add to the
 * built-in lists; `defaults` set to false removes the built-in lists (a
 * missing `defaults` or a missing `redact` means true). No callback.
 *
 * - **Key rules.** A key is split into segments at `_`, `-`, `.`, spaces
 *   and camelCase boundaries, lower-cased. It matches an entry when its
 *   trailing segments equal the entry's segments (`stripe_api_key` and
 *   `apiKey` match `api_key`), or when any of its segments equals a strong
 *   entry (STRONG: `db_password`, `apiToken`, `aws_secret_access_key`
 *   match; `classname` and `tokens_count` do not). Before the split, a
 *   URL-encoded key is decoded (`api%5Fkey`), a letter and a digit are
 *   split, and trailing number segments are dropped (`password2`,
 *   `apiKey2`). They apply to array
 *   keys, logical property names, and header records (a record with `name`
 *   or `key` and `value` whose name matches redacts `value`). The value
 *   becomes REDACTED, whatever its type.
 * - **Serialized PHP** (text()), on every string of job data, first and
 *   whatever the configuration: each fragment of serialized PHP (an
 *   object, a custom-serialized object or an array) becomes REDACTED, from
 *   its start to its matching `}`; a string alone (`s:<n>:"…";`) only when
 *   its `";` sits at its declared length. Its string lengths are followed, raw or
 *   JSON-escaped once, so a `}` inside a string never ends it; a fragment
 *   that does not end where its lengths and braces say is redacted to the
 *   end of the string (serialized()).
 * - **Pattern rules** (text()), on every string of job data, whole: the
 *   built-in bearer token, URL password (also without a user and with
 *   JSON-escaped slashes) and webhook URLs of known hosts (defaults only);
 *   pairs whose key matches the key rules (whenever a key entry exists):
 *   `key=value` (also `--key=value` and a URL query parameter),
 *   `key: value`, `"key": "value"` and its JSON-escaped form, PHP's
 *   `'key' => 'value'` and print_r's `[key] => value` (pairs()); then each
 *   configured pattern, a complete PCRE with delimiters and flags, whose
 *   whole match becomes REDACTED. Every built-in pattern runs in linear
 *   time: each run of labels is bounded and anchored, and the unbounded
 *   runs are possessive.
 * - **Failing closed.** A preg_* error or any Throwable on a value replaces
 *   that value with FAILED, and is reported through Watcher::report() with
 *   the step and the exception class only. An invalid configured rule
 *   makes text(), tree() and assertValid() throw RedactionConfigInvalid,
 *   which names only the rule's position; status() lists every invalid
 *   position for /api/health.
 *
 * Not final: the projection tests replace it with a throwing one.
 */
class Redactor
{
    public const REDACTED = '[redacted]';

    public const FAILED = '[redaction failed]';

    /**
     * The largest integer that a browser's JSON parser keeps exact (2^53 − 1). The tree sends an integer
     * beyond ±EXACT_INTEGER as {"integer": "<decimal digits>"}, a value and an array key alike.
     */
    public const EXACT_INTEGER = 9007199254740991;

    /** The single-segment entries that match any segment of a key. */
    public const STRONG = ['password', 'passwd', 'pwd', 'secret', 'token'];

    /** The other built-in entries, matched by the trailing segments of a key. */
    public const KEYS = [
        'password_confirmation', 'client_secret', 'access_token', 'refresh_token', 'api_key', 'apikey',
        'access_key', 'secret_key', 'private_key', 'authorization', 'proxy_authorization', 'x_api_key',
        'cookie', 'set_cookie', 'credit_card', 'card_number', 'cvv', 'cvc', 'ssn', 'signature', 'webhook_url',
    ];

    private const BEARER = '~Bearer\s+[A-Za-z0-9._\~+/=-]+~i';

    /**
     * The user and password of a URL; group 1 keeps the scheme's `://`, also JSON-escaped (`:\/\/`), and the
     * user may be empty. The password runs to the last `@` before a space, a slash or a backslash, so an
     * unencoded `@` in it (`root:P@ssw0rd@db`) is redacted whole; a part after an `@` holds no
     * quote and no comma, so the next value of a JSON or CSV text is never taken for a password. Every
     * part is possessive and stops at a slash, so the scan stays linear.
     */
    private const URL_PASSWORD = '~(:(?:\\\\?/){2})[^/\s:@\\\\]*+:[^@\s/\\\\]*+(?:@[^@\s/\\\\"\',]*+(?=@))*+@~u';

    /** Webhook URLs of known hosts; group 1 keeps the host and the fixed path, the rest is redacted. JSON-escaped slashes are allowed. */
    private const WEBHOOK = '~((?:https?:\\\\?/\\\\?/)?(?:hooks\.slack\.com\\\\?/(?:services|workflows|triggers)|(?:(?<![a-z0-9-])[a-z0-9-]{1,63}\.)?discord(?:app)?\.com\\\\?/api\\\\?/webhooks|outlook\.office\.com\\\\?/webhook|(?<![a-z0-9-])[a-z0-9-]{1,63}\.webhook\.office\.com))\\\\?/[^\s"\'<>]*+~i';

    /**
     * A key and its separator: after a non-key character or a JSON-escaped
     * line break (`\n`, `\r`, `\t`), an optional (escaped) quote or `[`,
     * up to two dashes (`--key`), the key (URL-encoded allowed), the quote
     * or `]`, and `=>`, `=` or `:`.
     */
    private const PAIR = '/(?:(?<![A-Za-z0-9_.\-%\\\\])|(?<=\\\\[nrt]))(\\\\*["\']|\[)?-{0,2}([A-Za-z_%][A-Za-z0-9_.\-%]{0,127})(\\\\*["\']|\])?[ \t]*(=>|[=:])[ \t]*/';

    /**
     * A pair inside a percent-encoded value (`%3Fpassword%3Dsecret%26…`):
     * after `%3F`, `%26` or a non-key character, the key, `%3D`, and the
     * value up to `%26` or a VALUE_END character. Possessive, so linear.
     */
    private const ENCODED_PAIR = '/(?:(?<![A-Za-z0-9_.\-%])|(?<=%3[Ff]|%26))([A-Za-z_][A-Za-z0-9_.\-]{0,127})%3[Dd]((?:[^\s&,;"\'<>)}\]%]++|%(?!26))*+)/';

    /**
     * The start of a print_r block at the offset: `Array`, `<Class> Object`
     * or `<Enum> Enum` (`Enum:string`), trailing spaces, a line break, the
     * indentation of the `(` line (group 1), and `(`.
     */
    private const PRINT_R_BLOCK = '/\G[ \t]*+(?:Array|[A-Za-z_\\\\][A-Za-z0-9_\\\\]*+ (?:Object|Enum(?::[a-z]++)?))[ \t]*+\r?\n([ \t]*+)\(/';

    /**
     * The start of serialized PHP in free text (an exception message that
     * quotes a job payload): an object `O:<n>:"`, a custom-serialized object
     * `C:<n>:"`, a string `s:<n>:"` (the quote also escaped: `O:<n>:\"`) or
     * an array `a:<n>:{`, after a non-name character or a JSON-escaped line
     * break.
     */
    private const SERIALIZED = '/(?:(?<![A-Za-z0-9_])|(?<=\\\\[nrt]))(?:[OCs]:\d{1,10}:\\\\*+"|a:\d{1,10}:\{)/';

    /** A serialized token with a byte length and a quoted value at the offset: group 1 its type, group 2 the length, group 3 the backslashes before the quote. */
    private const SERIALIZED_STRING = '/\G([sOCE]):(\d{1,10}):(\\\\*+)"/';

    /** The rest of a YAML key's line that opens a block scalar: `|` or `>`, an indicator, and an optional comment. */
    private const YAML_BLOCK = '/\A[|>][0-9+-]{0,2}[ \t]*(?:#.*)?\z/';

    /** An unquoted value that is one of these authorization schemes also covers the next token. */
    private const SCHEMES = ['basic', 'bearer', 'digest', 'token', 'negotiate', 'ntlm'];

    /** The characters that end an unquoted value after `=` or after a quoted key. */
    private const VALUE_END = " \t\r\n&,;\"'<>)}]";

    /** The characters of VALUE_END that do not end a value at its first byte (valueRange()). */
    private const LEADING_END = '&,;<>)}]';

    private const MEMO = 4096;

    private ?string $hash = null;

    /** @var array{defaults: bool, entries: list<list<string>>, patterns: list<string>, errors: list<string>}|null */
    private ?array $rules = null;

    /** @var array<string, bool> */
    private array $memo = [];

    /**
     * @throws RedactionConfigInvalid
     */
    public function assertValid(): void
    {
        $this->valid();
    }

    /**
     * The state of the configuration for /api/health: each invalid rule's
     * position, never its text.
     *
     * @return array{valid: bool, errors: list<array{field: string}>}
     */
    public function status(): array
    {
        $errors = $this->compiled()['errors'];

        return ['valid' => $errors === [], 'errors' => array_map(static fn (string $field): array => ['field' => $field], $errors)];
    }

    /**
     * Whether a key matches the key rules.
     *
     * @param  int|string|array<array-key, mixed>  $key
     *
     * @throws RedactionConfigInvalid
     */
    public function matchesKey(int|string|array $key): bool
    {
        if (! is_string($key) || $key === '') {
            return false;
        }

        $this->valid();

        return $this->keyMatches($key);
    }

    /**
     * The pattern rules over one string.
     *
     * @throws RedactionConfigInvalid
     */
    public function text(string $text): string
    {
        $rules = $this->valid();

        try {
            return $this->apply($text, $rules);
        } catch (Throwable $error) {
            $this->failed('text', $error);

            return self::FAILED;
        }
    }

    /**
     * A redacted copy of a tree as plain JSON data: strings through the
     * pattern rules, records and parsed nodes through the key rules.
     * stdClass stays an object, so `{}` stays `{}`. An integer beyond
     * ±EXACT_INTEGER becomes {"integer": "<digits>"} (exactInteger()).
     *
     * @throws RedactionConfigInvalid
     */
    public function tree(mixed $value): mixed
    {
        $rules = $this->valid();

        return $this->node($value, $rules);
    }

    /**
     * @return array{defaults: bool, entries: list<list<string>>, patterns: list<string>, errors: list<string>}
     *
     * @throws RedactionConfigInvalid
     */
    private function valid(): array
    {
        $rules = $this->compiled();

        if ($rules['errors'] !== []) {
            throw new RedactionConfigInvalid($rules['errors'][0]);
        }

        return $rules;
    }

    /**
     * @return array{defaults: bool, entries: list<list<string>>, patterns: list<string>, errors: list<string>}
     */
    private function compiled(): array
    {
        $config = Container::getInstance()->make('config')->get('orrery.redact');

        try {
            $hash = hash('sha256', serialize($config));
        } catch (Throwable) {
            $hash = null;
        }

        if ($hash === null || $hash !== $this->hash || $this->rules === null) {
            $this->rules = self::compile($config);
            $this->hash = $hash;
            $this->memo = [];
        }

        return $this->rules;
    }

    /**
     * @return array{defaults: bool, entries: list<list<string>>, patterns: list<string>, errors: list<string>}
     */
    private static function compile(mixed $config): array
    {
        $config ??= [];

        if (! is_array($config)) {
            return ['defaults' => true, 'entries' => [], 'patterns' => [], 'errors' => ['orrery.redact']];
        }

        $defaults = ($config['defaults'] ?? true) !== false;
        $errors = [];
        $entries = [];
        $patterns = [];
        $keys = $config['keys'] ?? [];

        if ($defaults) {
            foreach ([...self::STRONG, ...self::KEYS] as $key) {
                $entries[] = self::segments($key);
            }
        }

        if (! is_array($keys)) {
            $errors[] = 'orrery.redact.keys';
        } else {
            foreach ($keys as $index => $key) {
                $segments = is_string($key) ? self::segments($key) : [];

                if ($segments === []) {
                    $errors[] = "orrery.redact.keys.{$index}";
                } else {
                    $entries[] = $segments;
                }
            }
        }

        $configured = $config['patterns'] ?? [];

        if (! is_array($configured)) {
            $errors[] = 'orrery.redact.patterns';
        } else {
            foreach ($configured as $index => $pattern) {
                if (! is_string($pattern) || $pattern === '' || ! self::validPattern($pattern)) {
                    $errors[] = "orrery.redact.patterns.{$index}";
                } else {
                    $patterns[] = $pattern;
                }
            }
        }

        return ['defaults' => $defaults, 'entries' => $entries, 'patterns' => $patterns, 'errors' => $errors];
    }

    private static function validPattern(string $pattern): bool
    {
        set_error_handler(static fn (): bool => true);

        try {
            return preg_match($pattern, '') !== false;
        } catch (Throwable) {
            return false;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @return list<string>
     */
    private static function segments(string $key): array
    {
        // Fixed-width lookarounds, so a long run of capitals costs linear time; `([A-Z]+)([A-Z][a-z])` backtracked over it at every position.
        $spaced = preg_replace(['/(?<=[A-Z])(?=[A-Z][a-z])/', '/([a-z0-9])([A-Z])/', '/([A-Za-z])([0-9])/', '/([0-9])([A-Za-z])/'], [' ', '$1 $2', '$1 $2', '$1 $2'], $key) ?? $key;
        $segments = preg_split('/[_\-.\s]+/', strtolower($spaced), -1, PREG_SPLIT_NO_EMPTY);

        if ($segments === false) {
            return [];
        }

        // `password2`, `apiKey2`: a trailing number is not part of the name.
        while (count($segments) > 1 && ctype_digit((string) end($segments))) {
            array_pop($segments);
        }

        return $segments;
    }

    private function keyMatches(string $key): bool
    {
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        $rules = $this->rules ?? $this->compiled();
        $segments = self::segments(str_contains($key, '%') ? rawurldecode($key) : $key);
        $matches = false;

        if ($rules['defaults'] && array_intersect($segments, self::STRONG) !== []) {
            $matches = true;
        } else {
            foreach ($rules['entries'] as $entry) {
                if (count($segments) >= count($entry) && array_slice($segments, -count($entry)) === $entry) {
                    $matches = true;
                    break;
                }
            }
        }

        if (strlen($key) <= 256) {
            if (count($this->memo) >= self::MEMO) {
                $this->memo = [];
            }

            $this->memo[$key] = $matches;
        }

        return $matches;
    }

    /**
     * @param  array{defaults: bool, entries: list<list<string>>, patterns: list<string>, errors: list<string>}  $rules
     */
    private function apply(string $text, array $rules): string
    {
        $text = self::serialized($text);

        if ($rules['defaults']) {
            $text = self::replace(self::BEARER, 'Bearer '.self::REDACTED, $text);
            $text = self::replace(self::URL_PASSWORD, '$1'.self::REDACTED.'@', $text);
            $text = self::replace(self::WEBHOOK, '$1/'.self::REDACTED, $text);
        }

        if ($rules['entries'] !== []) {
            $text = $this->encodedPairs($this->pairs($text));
        }

        foreach ($rules['patterns'] as $pattern) {
            $text = self::replace($pattern, self::REDACTED, $text);
        }

        return $text;
    }

    /**
     * Each fragment of serialized PHP (SERIALIZED) becomes REDACTED, from
     * its start to its end (serializedEnd()), or to the end of the text when
     * it has none. A string alone (`s:<n>:"…";`) is a fragment only when it
     * ends where its length says (serializedStringEnd()); else it stays. A job payload inside an exception text (Laravel writes a
     * failed insert's bindings into the message) never reaches the browser.
     */
    private static function serialized(string $text): string
    {
        $length = strlen($text);
        $redacted = '';
        $cursor = 0;
        $offset = 0;

        while ($offset < $length) {
            $found = preg_match(self::SERIALIZED, $text, $match, PREG_OFFSET_CAPTURE, $offset);

            if ($found === false) {
                throw new RuntimeException('The serialized pattern failed.');
            }

            if ($found === 0) {
                break;
            }

            $start = $match[0][1];

            if ($text[$start] === 's') {
                // A string alone (a cache value in a failed upsert): only when its `";` sits at its declared length.
                $end = self::serializedStringEnd($text, $start, $length);

                if ($end === null) {
                    $offset = $start + 1;

                    continue;
                }
            } else {
                $end = self::serializedEnd($text, $start, $length) ?? $length;
            }

            $redacted .= substr($text, $cursor, $start - $cursor).self::REDACTED;
            $cursor = $offset = $end;
        }

        return $redacted === '' ? $text : $redacted.substr($text, $cursor);
    }

    /**
     * The offset after the serialized string that starts at $start (`s:<n>:"`,
     * raw or JSON-escaped once), through its closing quote and `;`. Null when
     * the quote and `;` are not at its declared byte length.
     */
    private static function serializedStringEnd(string $text, int $start, int $length): ?int
    {
        if (preg_match(self::SERIALIZED_STRING, $text, $token, 0, $start) !== 1) {
            return null;
        }

        $level = $token[3];
        $after = self::skipString($text, $start + strlen($token[0]), (int) $token[2], strlen($level), $length);

        if ($after === null || substr($text, $after, strlen($level) + 2) !== $level.'";') {
            return null;
        }

        return $after + strlen($level) + 2;
    }

    /**
     * The offset after the serialized value that starts at $start: after
     * the `}` that closes its first `{` (or, for a custom-serialized object
     * at the top, after its data). Strings are skipped by their byte length
     * (skipString()), so their content never counts as a brace. Null when
     * the value does not end so: a length that does not fit, an escaping
     * deeper than one JSON level, or a text that ends first.
     */
    private static function serializedEnd(string $text, int $start, int $length): ?int
    {
        $depth = 0;
        $at = $start;

        while ($at < $length) {
            $char = $text[$at];

            if ($char === '{' || $char === '}') {
                $depth += $char === '{' ? 1 : -1;
                $at++;

                if ($depth <= 0) {
                    return $depth === 0 ? $at : null;
                }

                continue;
            }

            if (! str_contains('sOCE', $char) || preg_match(self::SERIALIZED_STRING, $text, $token, 0, $at) !== 1) {
                $at++;

                continue;
            }

            $level = $token[3];
            $after = self::skipString($text, $at + strlen($token[0]), (int) $token[2], strlen($level), $length);

            if ($after === null || substr($text, $after, strlen($level) + 1) !== $level.'"') {
                return null;
            }

            $at = $after + strlen($level) + 1;

            // A custom-serialized object: its data is `:<n>:{` and n bytes of any text, then `}`.
            if ($token[1] === 'C' && preg_match('/\G:(\d{1,10}):\{/', $text, $data, 0, $at) === 1) {
                $inner = self::skipString($text, $at + strlen($data[0]), (int) $data[1], strlen($level), $length);

                if ($inner === null || ($text[$inner] ?? '') !== '}') {
                    return null;
                }

                $at = $inner + 1;

                if ($depth === 0) {
                    return $at;
                }
            }
        }

        return null;
    }

    /**
     * The offset after $bytes bytes of string content from $from: raw
     * ($level 0), or JSON-escaped once ($level 1: `\x` is one byte, `\uXXXX`
     * the bytes of its UTF-8 form, a surrogate pair four). Null for a deeper
     * escaping, a text that ends first, or a length that ends inside an
     * escape.
     */
    private static function skipString(string $text, int $from, int $bytes, int $level, int $length): ?int
    {
        if ($level === 0) {
            return $from + $bytes <= $length ? $from + $bytes : null;
        }

        if ($level !== 1) {
            return null;
        }

        $at = $from;
        $count = 0;

        while ($count < $bytes) {
            if ($at >= $length) {
                return null;
            }

            if ($text[$at] !== '\\') {
                $at++;
                $count++;

                continue;
            }

            if (preg_match('/\G\\\\u([0-9A-Fa-f]{4})/', $text, $escape, 0, $at) === 1) {
                $code = (int) hexdec($escape[1]);

                if ($code >= 0xD800 && $code <= 0xDBFF && preg_match('/\G\\\\u[Dd][C-Fc-f][0-9A-Fa-f]{2}/', $text, $low, 0, $at + 6) === 1) {
                    $count += 4;
                    $at += 12;
                } else {
                    $count += $code < 0x80 ? 1 : ($code < 0x800 ? 2 : 3);
                    $at += 6;
                }

                continue;
            }

            if ($at + 1 >= $length) {
                return null;
            }

            $at += 2;
            $count++;
        }

        return $count === $bytes ? $at : null;
    }

    private static function replace(string $pattern, string $with, string $text): string
    {
        $replaced = preg_replace($pattern, $with, $text);

        if ($replaced === null || preg_last_error() !== PREG_NO_ERROR) {
            throw new RuntimeException('A redaction pattern failed.');
        }

        return $replaced;
    }

    /**
     * The values of the pairs whose key matches the key rules (PAIR). It
     * reads one match at a time from an offset, so it keeps no list of
     * matches.
     *
     * - A quoted value ends at the next quote with exactly as many
     *   backslashes before it as its opening quote had; without one, the
     *   rest of the string is redacted.
     * - A value that starts with `[` or `{` ends at its matching bracket
     *   (quoted strings inside are skipped); without one, the rest of the
     *   string is redacted.
     * - An unquoted value after an unquoted key and `:` (a header line), or
     *   after `[key] =>` (print_r), runs to the end of the line; otherwise
     *   it ends at VALUE_END, and an authorization scheme (SCHEMES) also
     *   covers the next token (`authorization=Basic dXNl…`).
     */
    private function pairs(string $text): string
    {
        $length = strlen($text);
        $redacted = '';
        $cursor = 0;
        $offset = 0;

        while ($offset < $length) {
            $found = preg_match(self::PAIR, $text, $match, PREG_OFFSET_CAPTURE, $offset);

            if ($found === false) {
                throw new RuntimeException('The pair pattern failed.');
            }

            if ($found === 0) {
                break;
            }

            $start = $match[0][1];
            $end = $start + strlen($match[0][0]);
            $offset = max($end, $start + 1);

            if (! $this->keyMatches($match[2][0])) {
                continue;
            }

            $opening = $match[1][1] >= 0 ? $match[1][0] : '';
            $form = $opening === '[' ? 'bracket' : ($opening === '' ? 'plain' : 'quoted');
            [$from, $to] = self::valueRange($text, $end, $length, $match[4][0], $form);

            if ($to <= $from) {
                continue;
            }

            $redacted .= substr($text, $cursor, $from - $cursor).self::REDACTED;
            $cursor = $to;
            $offset = max($offset, $to);
        }

        return $cursor === 0 ? $text : $redacted.substr($text, $cursor);
    }

    /**
     * The values of the pairs inside percent-encoded values (ENCODED_PAIR)
     * whose key matches the key rules: `redirect=%2Flogin%3Fpassword%3Ds`
     * keeps `password%3D` and redacts the encoded value.
     */
    private function encodedPairs(string $text): string
    {
        if (stripos($text, '%3d') === false) {
            return $text;
        }

        $redacted = preg_replace_callback(
            self::ENCODED_PAIR,
            fn (array $match): string => $match[2] !== '' && $this->keyMatches($match[1]) ? $match[1].'%3D'.self::REDACTED : $match[0],
            $text,
        );

        if ($redacted === null || preg_last_error() !== PREG_NO_ERROR) {
            throw new RuntimeException('The encoded pair pattern failed.');
        }

        return $redacted;
    }

    /**
     * @param  'plain'|'quoted'|'bracket'  $form  how the key was written
     * @return array{0: int, 1: int}
     */
    private static function valueRange(string $text, int $at, int $length, string $separator, string $form): array
    {
        $run = strspn($text, '\\', $at);
        $quote = $text[$at + $run] ?? '';

        if ($quote === '"' || $quote === "'") {
            $open = $at + $run + 1;
            $search = $open;

            while (($position = strpos($text, $quote, $search)) !== false) {
                $backslashes = 0;

                while ($position - $backslashes - 1 >= $open && $text[$position - $backslashes - 1] === '\\') {
                    $backslashes++;
                }

                if ($backslashes === $run) {
                    return [$open, $position - $run];
                }

                $search = $position + 1;
            }

            return [$open, $length];
        }

        // print_r's values are not quoted, so its block ends at its own `)` line, never at a `)` inside a value.
        $block = self::printRBlockEnd($text, $at, $length);

        if ($block !== null) {
            return [$at, $block];
        }

        // A header line (`key: value`) and print_r's `[key] => value` run to the end of the line.
        $lineScoped = ($separator === ':' && $form === 'plain') || ($separator === '=>' && $form === 'bracket');

        if ($run === 0 && ($quote === '[' || $quote === '{')) {
            $end = self::bracketEnd($text, $at, $length);

            // A line-scoped value also covers the rest of the line where its bracket closes.
            return [$at, $lineScoped ? $end + strcspn($text, "\r\n", $end) : $end];
        }

        if ($lineScoped) {
            return self::lineValue($text, $at, $length, $form === 'bracket');
        }

        // var_export's `'key' => array (…)`, `(object) array(…)` and `\Class::__set_state(…)`, also on the
        // next line after `=>`; and any other value that is not line-scoped and starts so, to its matching `)`.
        $space = strspn($text, " \t\r\n", $at);
        $exported = preg_match(
            '/\A(?:\(object\)\s{0,8}array\s{0,8}(\()|\\\\?[A-Za-z_][A-Za-z0-9_\\\\]{0,255}::__set_state(\()|array\s{0,8}(\())/i',
            substr($text, $at + $space, 300),
            $array,
            PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL,
        );

        if ($exported === 1) {
            // The one group that matched holds the `(`; the others are at -1.
            $open = max($array[1][1], $array[2][1], $array[3][1]);

            return [$at + $space, self::bracketEnd($text, $at + $space + $open, $length)];
        }

        $to = $at + strcspn($text, self::VALUE_END, $at);

        if ($to === $at && strspn($text, self::LEADING_END, $at) > 0) {
            // A value that starts with a character that would end it (`password=&k3y`) runs to the
            // next space or quote: a key that names a secret loses more text rather than keep its value.
            $to = $at + strcspn($text, " \t\r\n\"'", $at);
        }

        if (in_array(strtolower(substr($text, $at, $to - $at)), self::SCHEMES, true)) {
            $next = $to + strspn($text, " \t", $to);
            // A token that the bearer pattern already redacted ends with its `]`.
            $tokenEnd = substr($text, $next, strlen(self::REDACTED)) === self::REDACTED
                ? $next + strlen(self::REDACTED)
                : $next + strcspn($text, self::VALUE_END, $next);

            if ($tokenEnd > $next) {
                $to = $tokenEnd;
            }
        }

        return [$at, $to];
    }

    /**
     * A line-scoped value (`key: value`, print_r's `[key] => value`): to the
     * end of its line. When the rest of the line is empty or only print_r's
     * `Array`, and the next non-blank text is `array (`, `(`, `[` or `{`,
     * the value starts there (print_r's nested array, var_export on the
     * next line) and runs through its matching closer (bracketEnd()) to the
     * end of that line. After print_r's `[key] =>`, a `[` on the next line
     * is a sibling key (`[next] => 1`), never a value: print_r opens a
     * nested value with `(`. A `Array` on the key's line is redacted with it;
     * an empty rest keeps its line break. Every scan is bounded by the
     * value it reads, so the pair scan stays linear.
     *
     * @return array{0: int, 1: int}
     */
    private static function lineValue(string $text, int $at, int $length, bool $printRKey): array
    {
        $lineEnd = $at + strcspn($text, "\r\n", $at);
        $word = $at + strspn($text, " \t", $at, $lineEnd - $at);
        $printR = $lineEnd - $word >= 5 && strncasecmp(substr($text, $word, 5), 'array', 5) === 0
            && $word + 5 + strspn($text, " \t", $word + 5, $lineEnd - $word - 5) === $lineEnd;

        if ($word === $lineEnd || $printR) {
            $next = $lineEnd + strspn($text, " \t\r\n", $lineEnd);
            // A print_r block on the next line; one on the key's line is found by valueRange().
            $block = $printR ? null : self::printRBlockEnd($text, $next, $length);

            if ($block !== null) {
                return [$next, $block];
            }

            $openings = $printRKey ? '/\A(?:array\s{0,8}\(|[({])/i' : '/\A(?:array\s{0,8}\(|[(\[{])/i';

            if (preg_match($openings, substr($text, $next, 16), $opening) === 1) {
                $end = self::bracketEnd($text, $next + strlen($opening[0]) - 1, $length);

                return [$printR ? $at : $next, $end + strcspn($text, "\r\n", $end)];
            }
        }

        // A YAML-style value on the following lines (an empty rest, or a `|` or `>` block scalar):
        // the lines that are blank or indented deeper than the key's line. Never after print_r's
        // `[key] =>`, whose deeper next line is a sibling key.
        $blockScalar = $word < $lineEnd && ($text[$word] === '|' || $text[$word] === '>')
            && preg_match(self::YAML_BLOCK, substr($text, $word, $lineEnd - $word)) === 1;

        if (! $printRKey && ($word === $lineEnd || $blockScalar)) {
            $end = self::deeperLinesEnd($text, $at, $lineEnd, $length);

            if ($end > $lineEnd) {
                return [$word === $lineEnd ? $lineEnd + strspn($text, " \t\r\n", $lineEnd) : $at, $end];
            }
        }

        return [$at, $lineEnd];
    }

    /**
     * The end of the last non-blank line after $lineEnd that is indented
     * deeper than the line that holds $at, or at the same indentation and a
     * YAML sequence item (`- x`, valid YAML under a key); blank lines between
     * them are included. $lineEnd when the next non-blank line is neither. Each
     * line is read once: linear. The key's line start is looked for at most
     * 4,096 bytes back; past that the indentation counts as 0, which only
     * redacts more.
     */
    private static function deeperLinesEnd(string $text, int $at, int $lineEnd, int $length): int
    {
        $before = strrpos(substr($text, max(0, $at - 4096), min($at, 4096)), "\n");
        $lineStart = $before === false ? max(0, $at - 4096) : max(0, $at - 4096) + $before + 1;
        $indent = strspn($text, " \t", $lineStart, $at - $lineStart);
        $end = $lineEnd;
        $position = $lineEnd;

        while ($position < $length) {
            $position += substr($text, $position, 2) === "\r\n" ? 2 : 1;
            $stop = $position + strcspn($text, "\r\n", $position);
            $spaces = strspn($text, " \t", $position, $stop - $position);

            if ($position + $spaces < $stop) {
                $item = $text[$position + $spaces] === '-' && in_array($text[$position + $spaces + 1] ?? "\n", [' ', "\t", "\r", "\n"], true);

                if ($spaces < $indent || ($spaces === $indent && ! $item)) {
                    break;
                }

                $end = $stop;
            }

            $position = $stop;
        }

        return $end;
    }

    /**
     * The end (exclusive) of the print_r block that starts at $at
     * (PRINT_R_BLOCK), or null when none starts there. print_r quotes no
     * value, so a `)` inside a value (`[0] => smile :)`) never closes the
     * block: it ends at the first `)` line with the indentation of its `(`
     * line, followed by a line break or the end of the text; the end of the
     * text when there is none. One forward search: linear.
     */
    private static function printRBlockEnd(string $text, int $at, int $length): ?int
    {
        if (preg_match(self::PRINT_R_BLOCK, $text, $match, 0, $at) !== 1) {
            return null;
        }

        $closing = "\n".$match[1].')';
        $search = $at + strlen($match[0]);

        while (($position = strpos($text, $closing, $search)) !== false) {
            $end = $position + strlen($closing);
            $after = $text[$end] ?? '';

            if ($after === '' || $after === "\n" || $after === "\r") {
                return $end;
            }

            $search = $position + 1;
        }

        return $length;
    }

    /**
     * The end (exclusive) of the bracketed value whose opening `[`, `{` or
     * `(` is at $at: the closer that empties a stack of expected closers.
     * Each opener pushes its own closer; a closer pops only when it is the
     * one expected, and any other closer is ignored, so a mismatched closer
     * never ends the value early. Single- and double-quoted strings and
     * their escapes are skipped (JSON, a Python or PHP repr, var_export).
     * The end of the text when the stack never empties. One pass: linear.
     */
    private static function bracketEnd(string $text, int $at, int $length): int
    {
        $closers = ['[' => ']', '{' => '}', '(' => ')'];
        $expected = [];

        for ($index = $at; $index < $length; $index++) {
            $character = $text[$index];

            if ($character === '"' || $character === "'") {
                for ($index++; $index < $length && $text[$index] !== $character; $index++) {
                    if ($text[$index] === '\\') {
                        $index++;
                    }
                }
            } elseif (isset($closers[$character])) {
                $expected[] = $closers[$character];
            } elseif ($expected !== [] && $character === $expected[count($expected) - 1]) {
                array_pop($expected);

                if ($expected === []) {
                    return $index + 1;
                }
            }
        }

        return $length;
    }

    /**
     * @param  array{defaults: bool, entries: list<list<string>>, patterns: list<string>, errors: list<string>}  $rules
     */
    private function node(mixed $value, array $rules): mixed
    {
        try {
            if (is_string($value)) {
                return $this->apply($value, $rules);
            }

            if ($value instanceof ParsedNode) {
                return $this->parsed($value, $rules);
            }

            if ($value instanceof stdClass) {
                return (object) $this->record(get_object_vars($value), $rules);
            }

            if (is_array($value)) {
                return array_is_list($value)
                    ? array_map(fn (mixed $item): mixed => $this->node($item, $rules), $value)
                    : $this->record($value, $rules);
            }

            if (is_int($value)) {
                return self::exactInteger($value);
            }

            if ($value === null || is_bool($value) || (is_float($value) && is_finite($value))) {
                return $value;
            }

            throw new RuntimeException('The tree holds a value of an unexpected type.');
        } catch (Throwable $error) {
            $this->failed('tree', $error);

            return self::FAILED;
        }
    }

    /**
     * A natural record (a JSON object, an associative array, a marker).
     *
     * @param  array<array-key, mixed>  $fields
     * @param  array{defaults: bool, entries: list<list<string>>, patterns: list<string>, errors: list<string>}  $rules
     * @return array<array-key, mixed>
     */
    private function record(array $fields, array $rules): array
    {
        $header = $this->isHeaderRecord($fields['name'] ?? null, $fields['key'] ?? null);
        $out = [];
        $suffixes = [];

        foreach ($fields as $key => $value) {
            $redact = (is_string($key) && $this->keyMatches($key)) || ($header && $key === 'value');
            $out[$this->outputKey($key, $out, $rules, $suffixes)] = $redact ? self::REDACTED : $this->node($value, $rules);
        }

        return $out;
    }

    /**
     * @param  array{defaults: bool, entries: list<list<string>>, patterns: list<string>, errors: list<string>}  $rules
     * @return array<string, mixed>
     */
    private function parsed(ParsedNode $node, array $rules): array
    {
        if ($node->kind === ParsedNode::SERIALIZED) {
            return ['serialized' => $this->node($node->value, $rules)];
        }

        // The first `name` and `key` entries, found without a map keyed by job-chosen keys.
        $name = null;
        $key = null;

        foreach ($node->entries as $entry) {
            if ($entry->key === 'name' && $name === null) {
                $name = $entry->value;
            } elseif ($entry->key === 'key' && $key === null) {
                $key = $entry->value;
            }
        }

        $header = $this->isHeaderRecord($name, $key);
        $entries = [];

        foreach ($node->entries as $entry) {
            $redact = (is_string($entry->key) && $this->keyMatches($entry->key)) || ($header && $entry->key === 'value');
            $key = match (true) {
                is_string($entry->key) => $this->apply($entry->key, $rules),
                is_int($entry->key) => self::exactInteger($entry->key),
                default => $entry->key,
            };
            $entries[] = $entry->with($key, $redact ? self::REDACTED : $this->node($entry->value, $rules))->toArray($node->kind === ParsedNode::OBJECT);
        }

        return $node->kind === ParsedNode::OBJECT
            ? ['class' => $node->class, 'properties' => $entries]
            : ['array' => $entries];
    }

    /**
     * An integer as the browser can read it exactly: as it is within
     * ±EXACT_INTEGER, else {"integer": "<decimal digits>"}.
     *
     * @return int|array{integer: string}
     */
    private static function exactInteger(int $value): int|array
    {
        return $value > self::EXACT_INTEGER || $value < -self::EXACT_INTEGER ? ['integer' => (string) $value] : $value;
    }

    private function isHeaderRecord(mixed $name, mixed $key): bool
    {
        return (is_string($name) && $name !== '' && $this->keyMatches($name))
            || (is_string($key) && $key !== '' && $this->keyMatches($key));
    }

    /**
     * A record key through the pattern rules; a changed key that collides
     * gets a suffix.
     *
     * @param  array<array-key, mixed>  $out
     * @param  array{defaults: bool, entries: list<list<string>>, patterns: list<string>, errors: list<string>}  $rules
     * @param  array<string, int>  $suffixes  the next suffix of each redacted text of this record
     */
    private function outputKey(int|string $key, array $out, array $rules, array &$suffixes): int|string
    {
        if (! is_string($key)) {
            return $key;
        }

        $redacted = $this->apply($key, $rules);

        if ($redacted === $key) {
            return $key;
        }

        $candidate = $redacted;

        // The next suffix of each redacted text is kept: many keys that redact alike (`token=1`,
        // `token=2`, …) would else each count up from 2, quadratic in their number.
        for ($suffix = $suffixes[$redacted] ?? 2; array_key_exists($candidate, $out); $suffix++) {
            $candidate = $redacted.' #'.$suffix;
        }

        $suffixes[$redacted] = $suffix;

        return $candidate;
    }

    private function failed(string $step, Throwable $error): void
    {
        try {
            // The step and the class only: the message or trace can carry job values (4.6, S-3).
            Watcher::instance()->report('projection:redact', "The redaction step {$step} threw ".$error::class.'.');
        } catch (Throwable) {
            // Nothing is left to report to.
        }
    }
}
