<?php

namespace Justrau\Orrery\Storage;

use PDOException;

/**
 * A PDOException of a package session that Laravel does not wrap in a
 * QueryException: a failed connect (PackageSession's PDO resolver, for
 * example in a begin or in the build of a pooled Postgres session) or a
 * failed raw exec() (PackageSession::exec()). It names its session, so
 * ErrorKinds classifies it by the rules of a QueryException of that session.
 *
 * The message, the code and errorInfo are the driver's. It has no previous
 * exception: the driver error is the whole of it, as in a QueryException.
 */
final class SessionPdoException extends PDOException
{
    public function __construct(public readonly string $session, PDOException $error)
    {
        parent::__construct($error->getMessage());

        $this->code = $error->getCode();
        $this->errorInfo = $error->errorInfo;
    }

    /**
     * $error with its session: an error that already names one keeps it.
     */
    public static function of(string $session, PDOException $error): self
    {
        return $error instanceof self ? $error : new self($session, $error);
    }
}
