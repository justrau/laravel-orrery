<?php

namespace Justrau\Orrery\Retry;

use stdClass;
/**
 * Takes the next step of one due operation of its action for the
 * OperationRunner: the retry handler and the forget
 * handler. A handler tags itself OperationRunner::HANDLERS.
 *
 * step() gets the ledger row without its payload columns (payload,
 * saved_record and error; read them with Operations::find()) and the
 * run's context, and returns what the runner does with the operation. It
 * reads and writes only through package sessions, writes a host SQLite file
 * only inside an operator action (D17), and should not throw: a throw
 * counts as an undecided check (`check_failed`) and is reported.
 */
interface OperationHandler
{
    /** The action that this handler steps: Operations::RETRY or Operations::FORGET. */
    public function action(): string;

    public function step(stdClass $operation, OperationContext $context): OperationStep;
}
