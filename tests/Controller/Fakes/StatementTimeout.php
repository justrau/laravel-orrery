<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller\Fakes;

use PDOException;

/**
 * A PDO error with the SQLSTATE of a Postgres statement timeout (57014): a
 * database error that is not a missing table.
 */
final class StatementTimeout extends PDOException
{
    public function __construct()
    {
        parent::__construct('SQLSTATE[57014]: Query canceled: canceling statement due to statement timeout');
        $this->code = '57014';
        $this->errorInfo = ['57014', 7, 'canceling statement due to statement timeout'];
    }
}
