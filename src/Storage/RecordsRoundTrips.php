<?php

namespace Justrau\Orrery\Storage;

use Illuminate\Database\Connection;

/**
 * Reports the transaction round trips of a package session to the test
 * recorder (PackageSession::recordForTesting()). A package session has no
 * event dispatcher, so Laravel fires no transaction event for it, and its
 * beforeExecuting callbacks see only Connection::run(). Each begin (one
 * combined begin on pooled Postgres), savepoint, commit and rollback is one
 * round trip here.
 *
 * The class that uses this trait extends Illuminate\Database\Connection.
 */
trait RecordsRoundTrips
{
    /** The PackageSession that built this session (prepare()); null for a session built elsewhere. */
    private ?PackageSession $owner = null;

    /**
     * The owning PackageSession: its recorder gets the round trips without a
     * container lookup on the hot path.
     */
    public function ownedBy(PackageSession $owner): void
    {
        $this->owner = $owner;
    }

    /**
     * One round trip of this session, reported to its owner's recorder.
     *
     * @param  array<mixed>  $bindings
     */
    public function recordRoundTrip(string $kind, string $sql, ?string $session = null, ?Connection $connection = null, array $bindings = []): void
    {
        if ($this->owner !== null) {
            $this->owner->recordTrip($kind, $sql, $session ?? $this->getName(), $connection, $bindings);

            return;
        }

        PackageSession::record($kind, $sql, $session ?? $this->getName(), $connection, $bindings);
    }

    /**
     * After each run() statement: the recorder gets an `executed` entry
     * (PackageSession::EXECUTED), which is not a round trip of its own. The
     * tests' event bridge fires its QueryExecuted there, after the
     * statement, as Laravel does.
     *
     * @param  string  $query
     * @param  array<mixed>  $bindings
     * @param  float|null  $time
     * @return void
     */
    public function logQuery($query, $bindings, $time = null)
    {
        parent::logQuery($query, $bindings, $time);

        $this->recordRoundTrip(PackageSession::EXECUTED, $query, $this->getName(), $this, $bindings);
    }

    /**
     * Laravel fires `committing` right before the COMMIT at level 1, in
     * commit() and in transaction(), also without a dispatcher.
     *
     * @param  string  $event
     * @return array<mixed>|null
     */
    protected function fireConnectionEvent($event)
    {
        if ($event === 'committing') {
            $this->recordRoundTrip(PackageSession::COMMIT, 'COMMIT', $this->getName(), $this);
        }

        return parent::fireConnectionEvent($event);
    }

    /**
     * @param  int  $toLevel
     * @return void
     */
    protected function performRollBack($toLevel)
    {
        if ($toLevel === 0) {
            $this->recordRoundTrip(PackageSession::ROLLBACK, 'ROLLBACK', $this->getName(), $this);
        } elseif ($this->getQueryGrammar()->supportsSavepoints()) {
            $this->recordRoundTrip(PackageSession::ROLLBACK, $this->getQueryGrammar()->compileSavepointRollBack('trans'.($toLevel + 1)), $this->getName(), $this);
        }

        parent::performRollBack($toLevel);
    }

    /**
     * Reports a begin or a savepoint, then lets Laravel send it.
     *
     * @return void
     */
    protected function createTransaction()
    {
        $this->recordBegin('BEGIN');

        parent::createTransaction();
    }

    protected function recordBegin(string $begin): void
    {
        if ($this->transactions === 0) {
            $this->recordRoundTrip(PackageSession::BEGIN, $begin, $this->getName(), $this);
        } elseif ($this->getQueryGrammar()->supportsSavepoints()) {
            $this->recordRoundTrip(PackageSession::BEGIN, $this->getQueryGrammar()->compileSavepoint('trans'.($this->transactions + 1)), $this->getName(), $this);
        }
    }
}
