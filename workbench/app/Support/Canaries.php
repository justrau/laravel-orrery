<?php

namespace Workbench\App\Support;

/**
 * The synthetic secrets of the redaction fixtures.
 *
 * Each value is distinct and starts with PREFIX, so a browser check (or a search of a response
 * body) finds every one that the dashboard shows unredacted. workbench/fixtures.md lists where
 * each one is stored. None of them is a real credential.
 */
final class Canaries
{
    public const PREFIX = 'orrery-canary-';

    /** A top-level key of the payload (`workbench_api_key`, added by the seeder's payload hook). */
    public const PAYLOAD_KEY = 'orrery-canary-payload-top-level-key';

    /** Command properties: public, protected and private. */
    public const PUBLIC_PROPERTY = 'orrery-canary-public-password';

    public const PROTECTED_PROPERTY = 'orrery-canary-protected-api-token';

    public const PRIVATE_PROPERTY = 'orrery-canary-private-client-secret';

    /** An array in the command, with the key forms. */
    public const ARRAY_SECRET_KEY = 'orrery-canary-array-secret-key';

    public const DB_PASSWORD = 'orrery-canary-db-password';

    public const STRIPE_SECRET = 'orrery-canary-stripe-secret';

    public const AWS_SECRET_ACCESS_KEY = 'orrery-canary-aws-secret-access-key';

    public const AUTH_TOKEN = 'orrery-canary-auth-token';

    /** A header record ({name, value}) with a bearer token. */
    public const HEADER = 'orrery-canary-header-bearer';

    /** A Slack webhook URL in a `webhook_url` property. */
    public const WEBHOOK = 'https://hooks.slack.com/services/T0WORKBENCH/B0WORKBENCH/orrery-canary-webhook';

    /** A URL with a password. */
    public const URL_PASSWORD = 'orrery-canary-url-password';

    /** The tail of a string over 64 KB, after `api_key=`: the pattern match needs the end. */
    public const LONG_TAIL = 'orrery-canary-long-string-tail';

    /** Laravel Context: a visible and a hidden value. */
    public const CONTEXT_VISIBLE = 'orrery-canary-context-visible';

    public const CONTEXT_HIDDEN = 'orrery-canary-context-hidden';

    /** The exception message (and so the failure history entry and the failure group). */
    public const EXCEPTION_PASSWORD = 'orrery-canary-exception-password';

    public const EXCEPTION_BEARER = 'orrery-canary-exception-bearer';

    /** The context of the exception (orrery_jobs.context and the history context). */
    public const EXCEPTION_CONTEXT = 'orrery-canary-exception-context';

    public const EXCEPTION_CONTEXT_HEADER = 'orrery-canary-exception-context-header';

    /** In the first line of the exception message, across its 500-character cut. */
    public const FIRST_LINE_CUT = 'orrery-canary-first-line-cut-straddle';

    /** The character where FIRST_LINE_CUT starts: it ends after character 500. */
    public const FIRST_LINE_CUT_AT = 490;

    /** A URL password near the end of a string over 64 KB: its `@` comes after byte 65,536. */
    public const LONG_URL_PASSWORD = 'orrery-canary-long-url-password-tail';

    /** Where the URL with LONG_URL_PASSWORD starts in SecretsJob::$longUrlNote. */
    public const LONG_URL_AT = 65500;

    /** A `webhook_secret` key of the command's array. */
    public const WEBHOOK_SECRET = 'orrery-canary-webhook-secret';

    /** The Slack route of a queued notification (AnonymousNotifiable). */
    public const NOTIFICATION_WEBHOOK = 'https://hooks.slack.com/services/T0WORKBENCH/B0NOTIFY/orrery-canary-notification-route';

    /** A value that a queued closure captures (a signed closure). */
    public const CLOSURE = 'orrery-canary-closure-capture';

    /** The command of an encrypted job. */
    public const ENCRYPTED = 'orrery-canary-encrypted-command';

    /** An option of the partial batch (`webhook_url`). */
    public const BATCH_OPTION = 'https://hooks.slack.com/services/T0WORKBENCH/B0BATCH/orrery-canary-batch-option';

    /**
     * The exception message of SecretsJob.
     */
    public static function exceptionMessage(): string
    {
        $head = sprintf(
            'The partner API refused the request: Authorization: Bearer %s password=%s callback=https://deploy:%s@partner.example.com/hook',
            self::EXCEPTION_BEARER,
            self::EXCEPTION_PASSWORD,
            self::URL_PASSWORD,
        );
        $key = ' token=';

        // One line: FIRST_LINE_CUT starts at character FIRST_LINE_CUT_AT and ends after 500.
        return $head.' '.str_repeat('-', self::FIRST_LINE_CUT_AT - strlen($head) - strlen($key) - 1).$key.self::FIRST_LINE_CUT;
    }

    /**
     * A string over 64 KB with a URL password that starts at LONG_URL_AT.
     */
    public static function longUrlNote(): string
    {
        $filler = substr(str_repeat('partner import log line ', intdiv(self::LONG_URL_AT, 24) + 1), 0, self::LONG_URL_AT);

        return $filler.'https://deploy:'.self::LONG_URL_PASSWORD.'@partner.example.com/hook';
    }
}
