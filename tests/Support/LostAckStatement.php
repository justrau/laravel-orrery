<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Support;

use PDO;
use PDOException;
use PDOStatement;

/**
 * A PDO::ATTR_STATEMENT_CLASS: execute() runs the statement on the server
 * (in autocommit it commits), then throws a lost-connection PDOException
 * ("server has gone away") once, for the first statement whose SQL starts
 * with the prefix (case-insensitive). Laravel's causedByLostConnection()
 * detects the message, so at transaction level 0 Laravel reconnects and runs
 * the statement again, and at level 1 or more it rethrows.
 *
 * connect() starts a new record: $executions counts the statements with the
 * prefix that ran, $lost the acknowledgements that were lost.
 */
final class LostAckStatement extends PDOStatement
{
    use PdoFromConfig;

    public static string $prefix = 'insert into';

    public static int $executions = 0;

    public static int $lost = 0;

    protected function __construct() {}

    /**
     * A PDO whose statements use this class.
     *
     * @param  array<string, mixed>  $config
     */
    public static function connect(array $config, string $sqlPrefix = 'insert into'): PDO
    {
        self::$prefix = $sqlPrefix;
        self::$executions = 0;
        self::$lost = 0;

        [$dsn, $username, $password, $options] = self::pdoArguments($config);
        $pdo = new PDO($dsn, $username, $password, $options);
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [self::class, []]);

        return $pdo;
    }

    public function execute(?array $params = null): bool
    {
        $result = parent::execute($params);

        if (stripos(ltrim($this->queryString), self::$prefix) === 0) {
            self::$executions++;

            if (self::$lost === 0) {
                self::$lost++;

                throw new PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away (the test lost the acknowledgement after the statement ran)');
            }
        }

        return $result;
    }
}
