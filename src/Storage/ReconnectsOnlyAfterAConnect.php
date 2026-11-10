<?php

namespace Justrau\Orrery\Storage;

use Closure;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * A server package session (MySQL, MariaDB, Postgres) reconnects only after
 * a connect that succeeded.
 *
 * Laravel's Connection reconnects and runs a statement or a begin again when
 * its lost-connection detector knows the error. When the session's own
 * connect failed in that statement (the session still holds its lazy PDO
 * resolver, which a successful connect replaces with the PDO), that reconnect
 * would only connect again to the same unreachable server: one more connect
 * timeout per statement. So the session throws the first error. A
 * connection that drops after a successful connect still reconnects once, as
 * Laravel does (with the one connect of SessionConnectionFactory).
 *
 * The class that uses this trait extends Illuminate\Database\Connection.
 */
trait ReconnectsOnlyAfterAConnect
{
    /**
     * @param  string  $query
     * @param  array<mixed>  $bindings
     * @return mixed
     *
     * @throws QueryException
     */
    protected function tryAgainIfCausedByLostConnection(QueryException $e, $query, $bindings, Closure $callback)
    {
        if ($this->connectFailed()) {
            throw $e;
        }

        return parent::tryAgainIfCausedByLostConnection($e, $query, $bindings, $callback);
    }

    /**
     * @return void
     *
     * @throws Throwable
     */
    protected function handleBeginTransactionException(Throwable $e)
    {
        if ($this->connectFailed()) {
            throw $e;
        }

        parent::handleBeginTransactionException($e);
    }

    /**
     * Whether the session still holds its lazy PDO resolver after an error:
     * the connect of this statement failed.
     */
    protected function connectFailed(): bool
    {
        return $this->pdo instanceof Closure;
    }
}
