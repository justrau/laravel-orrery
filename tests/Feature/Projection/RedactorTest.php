<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Projection;

use Justrau\Orrery\Http\Projection\ParsedEntry;
use Justrau\Orrery\Http\Projection\ParsedNode;
use Justrau\Orrery\Http\Projection\RedactionConfigInvalid;
use Justrau\Orrery\Http\Projection\Redactor;
use Justrau\Orrery\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

/**
 * The redaction rules: built-in defaults
 * that always apply, segment key matching, header records, delimited and
 * validated patterns, webhook hosts and query parameters, and failing
 * closed.
 */
final class RedactorTest extends TestCase
{
    /**
     * Rejects: exact-key matching (misses db_password, apiToken), substring
     * matching (redacts classname, tokens_count), or matching without the
     * camelCase split.
     *
     * @return array<string, array{string, bool}>
     */
    public static function keys(): array
    {
        return [
            'password' => ['password', true],
            'db_password' => ['db_password', true],
            'DB_PASSWORD' => ['DB_PASSWORD', true],
            'stripe_secret' => ['stripe_secret', true],
            'webhook_secret' => ['webhook_secret', true],
            'aws_secret_access_key' => ['aws_secret_access_key', true],
            'apiToken' => ['apiToken', true],
            'auth_token' => ['auth_token', true],
            'secret_key' => ['secret_key', true],
            'stripe_api_key' => ['stripe_api_key', true],
            'apiKey' => ['apiKey', true],
            'X-Api-Key' => ['X-Api-Key', true],
            'Authorization' => ['Authorization', true],
            'proxy-authorization' => ['proxy-authorization', true],
            'Set-Cookie' => ['Set-Cookie', true],
            'session cookie' => ['session cookie', true],
            'db.password' => ['db.password', true],
            'passwd' => ['passwd', true],
            'card_number' => ['card_number', true],
            'webhookUrl' => ['webhookUrl', true],
            'APIKey' => ['APIKey', true],
            'a strong segment that is not trailing' => ['password_reset_url', true],
            'token_type' => ['token_type', true],
            'secret_name' => ['secret_name', true],
            'classname' => ['classname', false],
            'tokens_count' => ['tokens_count', false],
            'name' => ['name', false],
            'key' => ['key', false],
            'api' => ['api', false],
            'api_key_id' => ['api_key_id', false],
            'cookie_name' => ['cookie_name', false],
            'passwordless' => ['passwordless', false],
        ];
    }

    #[DataProvider('keys')]
    public function test_key_rules(string $key, bool $matches): void
    {
        self::assertSame($matches, $this->redactor()->matchesKey($key), $key);
    }

    /**
     * Rejects: an integer beyond ±(2^53 − 1) sent as a JSON number (the
     * browser rounds 1234567890123456789 to 1234567890123456800, in a value
     * and in an array key), the safe integers wrapped too, and a value of
     * the JSON envelope left as a number.
     */
    public function test_an_integer_beyond_the_exact_range_of_a_browser_is_sent_as_text(): void
    {
        $tree = ParsedNode::array([
            new ParsedEntry('big', 1234567890123456789),
            new ParsedEntry('negative', PHP_INT_MIN),
            new ParsedEntry('edge', 9007199254740991),
            new ParsedEntry('past', 9007199254740992),
            new ParsedEntry('small', -9007199254740991),
            new ParsedEntry(9007199254740993, 'by key'),
        ]);

        self::assertSame(['array' => [
            ['key' => 'big', 'value' => ['integer' => '1234567890123456789']],
            ['key' => 'negative', 'value' => ['integer' => '-9223372036854775808']],
            ['key' => 'edge', 'value' => 9007199254740991],
            ['key' => 'past', 'value' => ['integer' => '9007199254740992']],
            ['key' => 'small', 'value' => -9007199254740991],
            ['key' => ['integer' => '9007199254740993'], 'value' => 'by key'],
        ]], $this->redactor()->tree($tree));

        $natural = json_decode('{"id": 1234567890123456789, "ids": [-9007199254740992, 7]}', false, 64);

        self::assertSame(
            '{"id":{"integer":"1234567890123456789"},"ids":[{"integer":"-9007199254740992"},7]}',
            json_encode($this->redactor()->tree($natural)),
        );
    }

    /**
     * Rejects: key rules applied only to the top level, only to natural
     * arrays, or to the logical name of a property but not its array
     * entries.
     */
    public function test_key_rules_apply_to_keys_properties_and_header_records(): void
    {
        $tree = ParsedNode::object('App\\Jobs\\Sync', [
            new ParsedEntry('password', 'canary-public', ParsedEntry::PUBLIC),
            new ParsedEntry('apiKey', 'canary-protected', ParsedEntry::PROTECTED),
            new ParsedEntry('secret', 'canary-private', ParsedEntry::PRIVATE, 'App\\Jobs\\Base'),
            new ParsedEntry('user', 'kept', ParsedEntry::PUBLIC),
            new ParsedEntry('options', ParsedNode::array([
                new ParsedEntry('access_token', 'canary-array'),
                new ParsedEntry(0, ParsedNode::array([new ParsedEntry('name', 'Authorization'), new ParsedEntry('value', 'canary-header')])),
                new ParsedEntry(1, ParsedNode::object('App\\Header', [new ParsedEntry('key', 'X-Api-Key', 'public'), new ParsedEntry('value', 'canary-header-object', 'public')])),
            ]), ParsedEntry::PUBLIC),
        ]);
        $natural = (object) ['headers' => [['name' => 'Cookie', 'value' => 'canary-natural-header']], 'db_password' => ['nested' => 'canary-natural'], 'name' => 'kept'];

        $json = (string) json_encode([$this->redactor()->tree($tree), $this->redactor()->tree($natural)]);

        foreach (['canary-public', 'canary-protected', 'canary-private', 'canary-array', 'canary-header', 'canary-header-object', 'canary-natural-header', 'canary-natural'] as $canary) {
            self::assertStringNotContainsString($canary, $json);
        }

        self::assertStringContainsString('"kept"', $json);
        self::assertSame(
            ['class' => 'App\\Jobs\\Sync', 'properties' => [
                ['name' => 'password', 'visibility' => 'public', 'value' => '[redacted]'],
                ['name' => 'apiKey', 'visibility' => 'protected', 'value' => '[redacted]'],
                ['name' => 'secret', 'visibility' => 'private', 'declared_in' => 'App\\Jobs\\Base', 'value' => '[redacted]'],
                ['name' => 'user', 'visibility' => 'public', 'value' => 'kept'],
            ]],
            ['class' => 'App\\Jobs\\Sync', 'properties' => array_slice($this->plain($this->redactor()->tree($tree))['properties'], 0, 4)],
        );
    }

    /**
     * Rejects: a pattern rule that is missing, or keeps the secret part.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function patterns(): array
    {
        return [
            'bearer' => ['Sent Authorization header Bearer abc.DEF-123_canary~+/= to the API', 'abc.DEF-123_canary', 'Bearer [redacted]'],
            'URL password' => ['Could not connect to mysql://root:canary-url-pass@db:3306/app', 'canary-url-pass', '://[redacted]@db'],
            // An unencoded `@` in the password, and a value that starts with a character that would end it.
            'URL password with an @' => ['Could not connect to mysql://root:P@ss-canary-at@db:3306/app', 'ss-canary-at', '://[redacted]@db:3306'],
            'URL password before a JSON value with an @' => ['{"dsn":"mysql://root:canary-json-dsn@db","mail":"a@b.test"}', 'canary-json-dsn', '"mail":"a@b.test"'],
            // A `/` or a space in a URL password must be percent-encoded (README, "Redaction").
            'URL password with an encoded slash' => ['Could not connect to mysql://root:pa%2Fss-canary-slash@db/app', 'ss-canary-slash', '://[redacted]@db/app'],
            'URL password with an encoded space' => ['Could not connect to mysql://root:pa%20ss-canary-space@db', 'ss-canary-space', '://[redacted]@db'],
            'value that starts with &' => ['Login failed password=&canary-amp now', 'canary-amp', 'password=[redacted] now'],
            'key=value' => ['Login failed for user=bob password=canary-kv now', 'canary-kv', 'password=[redacted] now'],
            'query parameter' => ['GET https://api.example.com/v1?access_token=canary-query&page=2 failed', 'canary-query', 'access_token=[redacted]&page=2'],
            'JSON pair' => ['Body: {"user":"bob","client_secret":"canary-json","n":1}', 'canary-json', '"client_secret":"[redacted]"'],
            'JSON pair with spaces' => ['{"api_key" : "canary-json-space"}', 'canary-json-space', '"api_key" : "[redacted]"'],
            'escaped JSON pair' => ['payload {\"password\":\"canary-escaped\",\"x\":1}', 'canary-escaped', '\"password\":\"[redacted]\"'],
            'JSON pair with an escaped quote' => ['{"password":"canary-a\"canary-b","n":1}', 'canary-b', '"password":"[redacted]","n":1'],
            'header line' => ["Request failed\nX-Api-Key: canary-header-line\nAccept: json", 'canary-header-line', "X-Api-Key: [redacted]\nAccept: json"],
            'unquoted JSON value' => ['{"pin_password":123456,"n":1}', '123456', '"pin_password":[redacted],"n":1'],
            'Slack webhook' => ['POST https://hooks.slack.com/services/T000/B000/canarySlack failed', 'canarySlack', 'https://hooks.slack.com/services/[redacted] failed'],
            'escaped Slack webhook' => ['"https:\/\/hooks.slack.com\/services\/T0\/B0\/canaryEscaped"', 'canaryEscaped', 'hooks.slack.com\/services/[redacted]'],
            'Discord webhook' => ['https://discord.com/api/webhooks/123/canaryDiscord', 'canaryDiscord', 'discord.com/api/webhooks/[redacted]'],
            'Discordapp webhook' => ['https://discordapp.com/api/webhooks/123/canaryDiscordApp', 'canaryDiscordApp', 'discordapp.com/api/webhooks/[redacted]'],
            'Outlook webhook' => ['https://outlook.office.com/webhook/abc/canaryOutlook', 'canaryOutlook', 'outlook.office.com/webhook/[redacted]'],
            'Office webhook' => ['https://contoso.webhook.office.com/webhookb2/canaryOffice', 'canaryOffice', 'contoso.webhook.office.com/[redacted]'],
            'Slack workflow webhook' => ['https://hooks.slack.com/workflows/T0/A0/canaryWorkflow', 'canaryWorkflow', 'hooks.slack.com/workflows/[redacted]'],
            'Slack trigger webhook' => ['https://hooks.slack.com/triggers/T0/1/canaryTrigger', 'canaryTrigger', 'hooks.slack.com/triggers/[redacted]'],
            'CLI option' => ['mysqldump --user=root --password=canary-cli app failed', 'canary-cli', '--password=[redacted] app'],
            'JSON-escaped line break' => ['"APP_ENV=prod\\nDB_PASSWORD=canary-escaped-env\\nX=1"', 'canary-escaped-env', 'DB_PASSWORD=[redacted]'],
            'JSON list value' => ['Request {"X-Api-Key":["canary-json-list"],"n":1}', 'canary-json-list', '"X-Api-Key":[redacted],"n":1'],
            'JSON object value' => ['{"password":{"old":"canary-obj-a","new":"canary-obj-b"},"n":1}', 'canary-obj-b', '"password":[redacted],"n":1'],
            'scheme-split credential' => ['curl -H authorization=Basic canaryBasicdXNlcjpwYXNz -X POST', 'canaryBasic', 'authorization=[redacted] -X POST'],
            'scheme-split credential with Digest' => ['proxy_authorization=Digest canaryDigest next', 'canaryDigest', 'proxy_authorization=[redacted] next'],
            'PHP array syntax' => ["['user' => 'bob', 'password' => 'canary-php-array']", 'canary-php-array', "'password' => '[redacted]'"],
            'print_r syntax' => ["Array\n(\n    [password] => canary print-r-tail\n    [user] => bob\n)", 'print-r-tail', "[password] => [redacted]\n    [user] => bob"],
            'scheme value inside braces' => ['headers={authorization=Bearer canary.braces, accept=json}', 'canary.braces', 'headers={authorization=[redacted], accept=json}'],
            'single-quoted bracket in a list' => ["{'token': ['a]', 'canary-repr'], 'n': 1}", 'canary-repr', "{'token': [redacted], 'n': 1}"],
            'var_export array' => ["array (\n  'password' => \n  array (\n    0 => 'canary-var-export',\n    1 => 'b)',\n  ),\n  'user' => 'bob',\n)", 'canary-var-export', "'password' => \n  [redacted],\n  'user' => 'bob'"],
            'var_export array on one line' => ["['password' => array ( 0 => 'canary-var-inline', ), 'n' => 1]", 'canary-var-inline', "'password' => [redacted], 'n' => 1"],
            'percent-encoded pair' => ['GET /auth?redirect=%2Flogin%3Fpassword%3Dcanary-encoded-pair%26next%3D1&x=1', 'canary-encoded-pair', 'password%3D[redacted]%26next%3D1&x=1'],
            // A mismatched closer never ends a bracketed value.
            'mismatched closer in JSON' => ['{"token": ["a", "b") canary-mismatch-json], "n": 1}', 'canary-mismatch-json', '{"token": [redacted], "n": 1}'],
            'mismatched closer after =' => ['token=[x) canary-mismatch-eq] tail', 'canary-mismatch-eq', 'token=[redacted] tail'],
            'unclosed bracket' => ['{"token": [x) canary-unclosed', 'canary-unclosed', '{"token": [redacted]'],
            // A line-scoped value runs to the end of its line, whatever its brackets.
            'array after a plain colon' => ["password: array (x) canary-line-colon\nnext: 1", 'canary-line-colon', "password: [redacted]\nnext: 1"],
            'array after print_r' => ["Array\n(\n    [password] => array (x) canary-line-print\n    [user] => bob\n)", 'canary-line-print', "[password] => [redacted]\n    [user] => bob"],
            'bracket after a plain colon' => ["X-Api-Key: [x] canary-after-bracket\nAccept: json", 'canary-after-bracket', "X-Api-Key: [redacted]\nAccept: json"],
            // A line-scoped value that starts on the next line.
            'print_r nested array' => ["Array\n(\n    [password] => Array\n        (\n            [0] => canary-print-nested\n        )\n\n    [next] => 1\n)", 'canary-print-nested', "[password] => [redacted]\n\n    [next] => 1\n)"],
            'array on the line after a colon' => ["password:\narray ( 0 => 'canary-next-colon', )\nnext: 1", 'canary-next-colon', "password:\n[redacted]\nnext: 1"],
            'array on the line after print_r' => ["[password] =>\n  array (\n    0 => 'canary-next-print',\n  )\n[next] => 1", 'canary-next-print', "[password] =>\n  [redacted]\n[next] => 1"],
            'bracket on the line after a colon' => ["password:\n  ['canary-next-bracket',\n   'b']\nnext: 1", 'canary-next-bracket', "password:\n  [redacted]\nnext: 1"],
            'text after the closer on the next line' => ["password:\n(a) canary-after-closer\nnext: 1", 'canary-after-closer', "password:\n[redacted]\nnext: 1"],
            // A print_r block ends at its own `)` line, whatever its values hold.
            'print_r block with a parenthesis in a value' => ["Array\n(\n    [password] => Array\n        (\n            [0] => smile :)\n            [1] => canary-print-paren\n        )\n\n    [user] => bob\n)", 'canary-print-paren', "[password] => [redacted]\n\n    [user] => bob\n)"],
            'print_r block after =' => ["password=Array\n(\n    [0] => smile :)\n    [1] => canary-eq-paren\n)\nafter=1", 'canary-eq-paren', "password=[redacted]\nafter=1"],
            'print_r object block' => ["[token] => stdClass Object\n        (\n            [a] => :)\n            [b] => canary-print-object\n        )\n", 'canary-print-object', "[token] => [redacted]\n"],
            'print_r block with CRLF' => ["Array\r\n(\r\n    [password] => Array\r\n        (\r\n            [0] => smile :)\r\n            [1] => canary-print-crlf\r\n        )\r\n\r\n    [user] => bob\r\n)", 'canary-print-crlf', "[password] => [redacted]\r\n\r\n    [user] => bob\r\n)"],
            'print_r block after = with CRLF' => ["password=Array\r\n(\r\n    [0] => smile :)\r\n    [1] => canary-eq-crlf\r\n)\r\nafter=1", 'canary-eq-crlf', "password=[redacted]\r\nafter=1"],
            'print_r block on the line after the key' => ["[password] =>\n    Array\n    (\n        [0] => :)\n        [1] => canary-next-block\n    )\n[next] => 1", 'canary-next-block', "[password] =>\n    [redacted]\n[next] => 1"],
            'print_r value line that starts with a parenthesis' => ["password=Array\n(\n    [0] => a\n)b canary-close-text\n    [1] => x\n)\nafter=1", 'canary-close-text', "password=[redacted]\nafter=1"],
            'unclosed print_r block' => ["password=Array\n(\n    [0] => :)\n    [1] => canary-unclosed-block", 'canary-unclosed-block', 'password=[redacted]'],
            // Print_r blocks with trailing spaces, and enums.
            'print_r block with trailing spaces' => ["[password] => Array   \n        (\n            [0] => :)\n            [1] => canary-trailing-space\n        )\n\n    [n] => 1", 'canary-trailing-space', "[password] => [redacted]\n\n    [n] => 1"],
            'print_r enum block' => ["[password] => Suit Enum:string\n        (\n            [name] => canary-enum\n            [value] => x\n        )\n\n    [n] => 1", 'canary-enum', "[password] => [redacted]\n\n    [n] => 1"],
            // Var_export objects.
            'var_export object cast' => ["'password' => \n  (object) array(\n     'v' => 'canary-object-cast',\n  ),\n  'n' => 1", 'canary-object-cast', "'password' => \n  [redacted],\n  'n' => 1"],
            'var_export __set_state' => ["'password' => \\App\\Key::__set_state(array(\n 'v' => 'canary-set-state',\n)),\n'n' => 1", 'canary-set-state', "'password' => [redacted],\n'n' => 1"],
            'not an array keyword' => ['password=arrayx (y) z', 'arrayx', 'password=[redacted] (y) z'],
            // YAML-style values on the following lines.
            'YAML value on the next line' => ["password:\n  canary-yaml-next\nnext: 1", 'canary-yaml-next', "password:\n  [redacted]\nnext: 1"],
            'nested YAML value' => ["db:\n  password:\n    canary-yaml-nested\n  host: x", 'canary-yaml-nested', "  password:\n    [redacted]\n  host: x"],
            'YAML literal block' => ["private_key: |\n  canary-yaml-block\n\n  more-canary-yaml\nnext: 1", 'more-canary-yaml', "private_key: [redacted]\nnext: 1"],
            'YAML folded block' => ["password: >-\n  canary-yaml-folded", 'canary-yaml-folded', 'password: [redacted]'],
            'YAML value before a blank line' => ["password:\n  canary-yaml-blank\n\nnext: 1", 'canary-yaml-blank', "password:\n  [redacted]\n\nnext: 1"],
            // A sequence at the key's indentation, and a block indicator with a comment.
            'YAML sequence at the key indentation' => ["api_key:\n- canary-seq\n- more-canary-seq\nnext: 1", 'more-canary-seq', "api_key:\n[redacted]\nnext: 1"],
            'nested YAML sequence at the key indentation' => ["db:\n  password:\n  - canary-nested-seq\n  host: x", 'canary-nested-seq', "  password:\n  [redacted]\n  host: x"],
            'YAML block with a comment' => ["private_key: | # pem\n  canary-yaml-comment\nnext: 1", 'canary-yaml-comment', "private_key: [redacted]\nnext: 1"],
            'YAML block with a long comment' => ["private_key: |- # the PEM key of the payment API\n  canary-yaml-long-comment\nnext: 1", 'canary-yaml-long-comment', "private_key: [redacted]\nnext: 1"],
            'text after a block indicator is not a block' => ["token: | not a block\n  visible-deeper\nnext: 1", 'not a block', "token: [redacted]\n  visible-deeper\nnext: 1"],
            'YAML list' => ["api_key:\n  - canary-yaml-list\nnext: 1", 'canary-yaml-list', "api_key:\n  [redacted]\nnext: 1"],
            'array after =' => ['password=array (canary-eq-array) next=1', 'canary-eq-array', 'password=[redacted] next=1'],
            'bracket over lines after a plain colon' => ["password: [a,\nb] canary-after-lines\nnext: 1", 'canary-after-lines', "password: [redacted]\nnext: 1"],
            'URL password without a user' => ['Redis failed at redis://:canary-redis@cache:6379', 'canary-redis', 'redis://[redacted]@cache'],
            'URL password with escaped slashes' => ['{"dsn":"mysql:\\/\\/root:canary-escaped-dsn@db\\/app"}', 'canary-escaped-dsn', 'mysql:\\/\\/[redacted]@db'],
            'a key with a trailing digit' => ['password2=canary-digit next', 'canary-digit', 'password2=[redacted] next'],
            'a camelCase key with a trailing digit' => ['{"apiKey2":"canary-camel-digit"}', 'canary-camel-digit', '"apiKey2":"[redacted]"'],
            'a URL-encoded key' => ['GET /v1?api%5Fkey=canary-encoded&page=2', 'canary-encoded', 'api%5Fkey=[redacted]&page=2'],
        ];
    }

    #[DataProvider('patterns')]
    public function test_pattern_rules(string $text, string $secret, string $expected): void
    {
        $redacted = $this->redactor()->text($text);

        self::assertStringNotContainsString($secret, $redacted);
        self::assertStringContainsString($expected, $redacted);
    }

    /**
     * Serialized PHP inside free text: the exception of a failed
     * insert of a job payload, raw and JSON-escaped once, with strings that
     * hold a brace, a quote, a line break, a multibyte letter and a
     * surrogate pair before the secret.
     *
     * Rejects: no serialized rule (the pair rules find only `s`, `O` and
     * `data`); a fragment that ends at the first `}` inside a string (the
     * secret after it stays); a fragment that ends at the first quote; and a
     * JSON-escaped string skipped by its escaped length instead of its
     * decoded bytes.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function serializedTexts(): array
    {
        $object = new stdClass;
        $object->note = "a}b\"c\n\u{00FC}\u{1F600}{";
        $object->apiToken = 'sk_live_canary_object';
        $command = serialize($object);
        $array = serialize(['note' => 'x}y', 'n' => 1, 'customer' => 'canary-array']);
        $payload = json_encode(['uuid' => 'u1', 'data' => ['command' => $command]], JSON_UNESCAPED_SLASHES);
        $ascii = json_encode(['data' => ['command' => $command]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // serialize() of `class SyncJob { protected $url = 'https://…'; public $queue = null; }`, and the payload as
        // Queue::createPayload() encodes it (JSON_UNESCAPED_UNICODE only): `\u0000`, `\/` and `\\` appear.
        $class = 'App\\Jobs\\SyncJob';
        $property = "\0*\0url";
        $job = 'O:'.strlen($class).':"'.$class.'":2:{s:'.strlen($property).':"'.$property.'";'
            .serialize('https://hooks.example.test/v1/sync?token=canary-url-token').'s:5:"queue";N;}';
        $laravel = json_encode(['uuid' => 'u2', 'displayName' => $class, 'data' => ['commandName' => $class, 'command' => $job]], JSON_UNESCAPED_UNICODE);
        $insert = "SQLSTATE[HY000]: General error: 5 database is locked (Connection: sqlite, SQL: insert into \"jobs\" (\"queue\", \"payload\", \"attempts\") values (default, {$laravel}, 0))";
        $insertTail = '"command":"[redacted]"}}, 0))';

        return [
            'raw object' => ["insert failed: {$command} (Connection: x)", 'sk_live_canary_object', 'insert failed: [redacted] (Connection: x)'],
            'raw array' => ["values ({$array}, 2)", 'canary-array', 'values ([redacted], 2)'],
            'JSON-escaped object' => ["insert into \"jobs\" values ({$payload}, 0)", 'sk_live_canary_object', 'values ({"uuid":"u1","data":{"command":"[redacted]"}}, 0)'],
            'JSON-escaped object with unescaped unicode' => ["values ({$ascii})", 'sk_live_canary_object', 'values ({"data":{"command":"[redacted]"}})'],
            'after an escaped line break' => ['"line\\n'.substr((string) json_encode($command), 1), 'sk_live_canary_object', '"line\\n[redacted]'],
            'cut fragment' => ['failed: '.substr($command, 0, -12), 'sk_live_cana', 'failed: [redacted]'],
            'doubly escaped' => ['values '.json_encode(json_encode(['command' => $command])).' tail-canary', 'sk_live_canary_object', 'values "{\\"command\\":\\"[redacted]'],
            'two fragments' => ["a {$array} b {$command} c", 'canary', 'a [redacted] b [redacted] c'],
            'string of a failed cache upsert' => ['values (laravel_cache_stripe, '.serialize('sk_live_canary_cache_x').', 1790000000)', 'sk_live_canary_cache', 'values (laravel_cache_stripe, [redacted], 1790000000)'],
            'string with a quote and a semicolon' => ['value '.serialize('a";b canary-string-quote').' tail', 'canary-string-quote', 'value [redacted] tail'],
            'JSON-escaped string' => ['{"v":'.json_encode(serialize('canary-escaped-string')).'}', 'canary-escaped-string', '{"v":"[redacted]"}'],
            'Laravel payload of a class with a protected property' => [$insert, 'canary-url-token', $insertTail],
        ];
    }

    #[DataProvider('serializedTexts')]
    public function test_serialized_php_in_free_text_is_redacted(string $text, string $secret, string $expected): void
    {
        $redacted = $this->redactor()->text($text);

        self::assertStringNotContainsString($secret, $redacted);
        self::assertStringContainsString($expected, $redacted);
    }

    /**
     * Rejects: a serialized rule that belongs to the built-in lists, so
     * `defaults: false` shows the serialized payload again.
     */
    public function test_serialized_php_is_redacted_without_the_defaults(): void
    {
        $this->app['config']->set('orrery.redact', ['defaults' => false]);

        self::assertSame('x [redacted] y', $this->redactor()->text('x '.serialize(['k' => 'canary-off']).' y'));
    }

    /**
     * The limit that the README states: the URL rule cannot tell a password
     * with an unencoded `/` or space from a port and a path
     * (`host:8080/path?mail=a@b`) or from the text after a URL, so it leaves
     * such a password as it is.
     *
     * Rejects: a URL rule that takes the password up to the last `@` across
     * a `/` or a space, and so eats the path or the text before an e-mail
     * address; and a silent change of the stated limit (this test and the
     * README change together).
     * Still passes: a rule that redacts such a password by a pattern that
     * only this text matches.
     */
    public function test_a_url_password_with_an_unencoded_slash_or_space_is_the_stated_limit(): void
    {
        foreach ([
            'Could not connect to mysql://root:pa/ss@db/app',
            'Could not connect to mysql://root:pa ss@db',
            'GET http://example.com:8080/path?mail=a@b.test failed',
            'See http://example.com:8080 and write to ops@example.test',
            'GET http://[::1]:8080/a@b failed',
        ] as $text) {
            self::assertSame($text, $this->redactor()->text($text));
        }

        self::assertStringContainsString('holds no unencoded `/`, space or backslash', (string) file_get_contents(dirname(__DIR__, 3).'/README.md'));
    }

    /**
     * Rejects: a rule that redacts text without a secret.
     */
    public function test_text_without_secrets_is_unchanged(): void
    {
        foreach ([
            'Call to undefined method App\\Jobs\\Sync::handle()',
            'SQLSTATE[23000]: Integrity constraint violation at 12:30',
            'Visit https://example.com/path?page=2&sort=name',
            'classname=Foo tokens_count=3 name: Bob',
            'C:\\temp\\file.txt',
            "password:\nnext: 1",
            "token:\nnext: visible",
            "items:\n- a\n- b",
            "password:\n-x: 1",
            "token:\n\nnext: visible",
            "[token] =>\n    [user] => bob",
            'GET http://example.com:8080/a@b failed',
            'header x-api-key missing; classname=Foo tokens_count=3',
            'payload {\\"api_key_id\\":5}',
            'Retry a:1 at O:2 with s:9:"abc"; and s:3:"abcd";',
            'PROTO:12:"x" and LOGIC:3:"abc"',
        ] as $text) {
            self::assertSame($text, $this->redactor()->text($text));
        }
    }

    /**
     * Each built-in pattern on 64 KB rows that make a backtracking pattern
     * rescan.
     *
     * Rejects: an unbounded label run in the webhook pattern (quadratic: a
     * 64 KB row took 1.6 to 2.2 s), or any other built-in pattern with
     * super-linear time.
     */
    public function test_built_in_patterns_run_in_linear_time(): void
    {
        $redactor = $this->redactor();
        $rows = [
            'a run before a slash' => str_repeat('a', 65_535).'/',
            'hex before a slash' => bin2hex(random_bytes(32_767)).'/',
            'labels before a slash' => str_repeat('a-', 32_767).'/',
            'pairs' => str_repeat('a=', 32_768),
            'colons after a scheme' => '://'.str_repeat('a:', 32_766),
            'bearer words' => str_repeat('Bearer ', 9_362),
            'dots before a host' => str_repeat('a.', 32_767).'/',
            'secret pairs' => str_repeat('token=', 10_922),
            'quotes' => str_repeat('"password":"', 5_461),
            'brackets' => 'password=['.str_repeat('[', 65_000),
            'encoded pairs' => str_repeat('a%3D', 16_384),
            'encoded separators' => str_repeat('%3Fa', 16_384),
            'encoded secret pairs' => str_repeat('token%3D', 8_192),
            'open arrays' => 'password => '.str_repeat('array (', 9_000),
            'single quotes in brackets' => 'password=['.str_repeat("'", 65_000),
            'mismatched closers' => 'token=['.str_repeat(')', 65_000),
            'deep mismatched pairs' => 'token=['.str_repeat('[)', 32_000),
            'line-scoped brackets' => 'password: '.str_repeat('[', 65_000),
            'line-scoped arrays' => '[password] => '.str_repeat('array (', 9_000),
            'empty line-scoped values' => str_repeat("password:\n", 6_553),
            'print_r arrays on each line' => str_repeat("[password] => Array\n", 3_276),
            'print_r parentheses in values' => '[password] => Array'."\n(\n".str_repeat("  [0] => :)\n", 5_000),
            'print_r objects with trailing spaces' => '[password] => '.str_repeat("X Object   \n", 6_000),
            'YAML keys with values' => str_repeat("password:\n  canary\n", 6_000),
            'YAML keys at one depth' => "a:\n".str_repeat("  password:\n", 6_000),
            'YAML sequence lines' => "api_key:\n".str_repeat("- x\n", 16_000),
            'YAML keys with sequences' => str_repeat("api_key:\n- x\n", 6_000),
            'deeper YAML lines' => "password:\n".str_repeat("   x\n", 16_000),
            'open parentheses on the next line' => "password:\n".str_repeat('(', 65_000),
        ];

        foreach ($rows as $name => $row) {
            $started = hrtime(true);
            $redactor->text($row);
            self::assertLessThan(0.5, (hrtime(true) - $started) / 1e9, $name);
        }
    }

    /**
     * Rejects: two redactor steps that are quadratic on hostile keys: many keys that redact alike
     * (`token=1`, `token=2`, …) each counted their suffix up from 2, and a long run of capitals in a key
     * backtracked at every position of the camel-case split. Both finish well within the bound, and the
     * names stay distinct.
     * Still passes: a linear rule whose suffixes are not consecutive (only distinct names are asserted).
     */
    public function test_hostile_keys_cost_linear_time(): void
    {
        $record = [];

        for ($n = 1; $n <= 30_000; $n++) {
            $record['token='.$n] = $n;
        }

        $record[str_repeat('A', 60_000).'Password'] = 'canary-caps';
        $started = microtime(true);
        $tree = $this->plain($this->redactor()->tree((object) $record));
        $seconds = microtime(true) - $started;

        self::assertIsArray($tree);
        self::assertCount(30_001, $tree);
        self::assertArrayHasKey('token=[redacted] #30000', $tree);
        self::assertStringNotContainsString('canary-caps', (string) json_encode($tree));
        self::assertLessThan(10.0, $seconds);
    }

    /**
     * A 64 KB string whose match needs its end.
     *
     * Rejects: patterns applied to a truncated prefix.
     */
    public function test_patterns_match_the_whole_bounded_value(): void
    {
        $text = str_repeat('a', 65_000).' mysql://root:canary-at-the-end@db';

        self::assertStringNotContainsString('canary-at-the-end', $this->redactor()->text($text));
    }

    /**
     * Rejects: configuration that replaces the built-in lists (the
     * top-level merge of mergeConfigFrom()).
     */
    public function test_configured_rules_add_to_the_defaults(): void
    {
        $this->app['config']->set('orrery.redact', ['keys' => ['customer_pin'], 'patterns' => ['~ACME-\d{4}~']]);
        $redactor = $this->redactor();

        self::assertTrue($redactor->matchesKey('customer_pin'));
        self::assertTrue($redactor->matchesKey('order_customer_pin'));
        self::assertTrue($redactor->matchesKey('password'), 'The built-in keys still apply.');
        self::assertTrue($redactor->matchesKey('stripe_api_key'), 'The built-in trailing entries still apply.');
        self::assertTrue($redactor->matchesKey('Authorization'));
        self::assertSame('code [redacted] Bearer [redacted]', $redactor->text('code ACME-1234 Bearer abc'));
    }

    /**
     * Rejects: an opt-out that is ignored, or one that also drops the
     * configured rules.
     */
    public function test_defaults_false_removes_only_the_built_in_lists(): void
    {
        $this->app['config']->set('orrery.redact', ['defaults' => false, 'keys' => ['customer_pin'], 'patterns' => ['~ACME-\d{4}~']]);
        $redactor = $this->redactor();

        self::assertFalse($redactor->matchesKey('password'));
        self::assertFalse($redactor->matchesKey('db_password'));
        self::assertTrue($redactor->matchesKey('customer_pin'));
        self::assertSame('code [redacted] Bearer abc', $redactor->text('code ACME-1234 Bearer abc'));
    }

    /**
     * A published configuration without the new nested keys:
     * a missing `defaults` means true, a missing `redact` means the
     * package's defaults.
     *
     * Rejects: a reader without its default constant (a missing
     * redact.defaults read as false).
     */
    public function test_missing_keys_take_their_defaults(): void
    {
        $this->app['config']->set('orrery.redact', ['keys' => ['customer_pin']]);
        self::assertTrue($this->redactor()->matchesKey('password'));

        $this->app['config']->set('orrery.redact', null);
        self::assertTrue($this->redactor()->matchesKey('password'));
        self::assertSame(['valid' => true, 'errors' => []], $this->redactor()->status());
    }

    /**
     * Rejects: an invalid pattern ignored (fail open), or an error that
     * names the pattern text.
     */
    public function test_an_invalid_pattern_fails_closed(): void
    {
        $this->app['config']->set('orrery.redact', ['patterns' => ['~valid~', 'canary-not-delimited(', '~ok~', ['not a string']]]);
        $redactor = $this->redactor();

        try {
            $redactor->text('anything');
            self::fail('An invalid pattern must refuse.');
        } catch (RedactionConfigInvalid $error) {
            self::assertSame('orrery.redact.patterns.1', $error->field);
            self::assertStringNotContainsString('canary', $error->getMessage());
        }

        $status = $redactor->status();
        self::assertSame(['valid' => false, 'errors' => [['field' => 'orrery.redact.patterns.1'], ['field' => 'orrery.redact.patterns.3']]], $status);
        self::assertStringNotContainsString('canary', (string) json_encode($status));

        $this->expectException(RedactionConfigInvalid::class);
        $redactor->tree(['a' => 'b']);
    }

    /**
     * Rejects: invalid key entries ignored.
     */
    public function test_invalid_configured_keys_fail_closed(): void
    {
        $this->app['config']->set('orrery.redact', ['keys' => ['ok', '', 12], 'patterns' => 'not a list']);

        self::assertSame(['valid' => false, 'errors' => [['field' => 'orrery.redact.keys.1'], ['field' => 'orrery.redact.keys.2'], ['field' => 'orrery.redact.patterns']]], $this->redactor()->status());
    }

    /**
     * An Octane request with another configuration recompiles (the rules
     * are cached by a hash of orrery.redact).
     *
     * Rejects: rules compiled once per process.
     */
    public function test_a_changed_configuration_recompiles(): void
    {
        $redactor = $this->redactor();
        self::assertFalse($redactor->matchesKey('customer_pin'));

        $this->app['config']->set('orrery.redact.keys', ['customer_pin']);
        self::assertTrue($redactor->matchesKey('customer_pin'));
        self::assertSame($redactor, $this->app->make(Redactor::class), 'The redactor is a singleton.');
    }

    /**
     * A pattern that exhausts PCRE's backtracking limit.
     *
     * Rejects: the original string returned after a preg_* error.
     */
    public function test_a_preg_error_fails_closed(): void
    {
        $this->app['config']->set('orrery.redact.patterns', ['~(?:\D+|<\d+>)*[!?]~']);

        self::assertSame('[redaction failed]', $this->redactor()->text('canary-backtrack '.str_repeat('foobar foobar foobar ', 50)));
        self::assertSame(['array' => [['key' => 'k', 'value' => '[redaction failed]']]], $this->plain($this->redactor()->tree(ParsedNode::array([new ParsedEntry('k', 'canary '.str_repeat('foobar foobar foobar ', 50))]))));
    }

    /**
     * Rejects: a secret in a key that is never redacted, and markers or
     * stdClass objects turned into other shapes.
     */
    public function test_tree_shapes(): void
    {
        $tree = (object) ['empty' => new stdClass, 'list' => [1, 'Bearer abc'], 'marker' => ['omitted' => 'too_large', 'bytes' => 3], 'Bearer canary-key' => 1];

        self::assertSame('{"empty":{},"list":[1,"Bearer [redacted]"],"marker":{"omitted":"too_large","bytes":3},"Bearer [redacted]":1}', json_encode($this->redactor()->tree($tree)));
    }

    private function redactor(): Redactor
    {
        return $this->app->make(Redactor::class);
    }

    private function plain(mixed $tree): mixed
    {
        return json_decode((string) json_encode($tree), true, 512, JSON_THROW_ON_ERROR);
    }
}
