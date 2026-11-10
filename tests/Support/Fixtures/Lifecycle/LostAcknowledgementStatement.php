<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support\Fixtures\Lifecycle;

use Justrau\Orrery\Tests\Support\AutocommitBounds;
use PDOException;
use PDOStatement;

/**
 * A PDO statement class that loses the acknowledgement of one statement:
 * the first execution whose SQL matches the armed pattern runs on the
 * server (and commits in autocommit), then throws a lost-connection error.
 * Laravel's causedByLostConnection() detects the message, so Laravel
 * reconnects at transaction level 0 and runs the statement again.
 *
 * The pattern is matched against the statement without the autocommit
 * bounds of a pooled Postgres session (AutocommitBounds), so `/^insert /`
 * matches the same statement in both modes.
 *
 * Install it with PDO::ATTR_STATEMENT_CLASS on a PDO from
 * PackageSession::usePdoFactoryForTesting(). The state is static, so the new PDO
 * after the reconnect shares it.
 */
class LostAcknowledgementStatement extends PDOStatement
{
    /** The pattern of the statement whose acknowledgement is lost, or null. */
    public static ?string $armed = null;

    /** How many times a statement that matches the armed pattern executed. */
    public static int $executions = 0;

    /** How many acknowledgements were lost. */
    public static int $lost = 0;

    protected function __construct()
    {
    }

    public static function arm(string $pattern): void
    {
        self::$armed = $pattern;
        self::$executions = 0;
        self::$lost = 0;
    }

    public static function reset(): void
    {
        self::$armed = null;
        self::$executions = 0;
        self::$lost = 0;
    }

    public function execute(?array $params = null): bool
    {
        $result = parent::execute($params);
        $pattern = self::$armed;

        if ($pattern !== null && preg_match($pattern, AutocommitBounds::strip($this->queryString)) === 1) {
            self::$executions++;

            if (self::$lost === 0) {
                self::$lost++;

                throw new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away (the test lost the acknowledgement after the statement ran)');
            }
        }

        return $result;
    }
}
