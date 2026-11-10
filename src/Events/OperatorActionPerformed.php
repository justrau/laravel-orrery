<?php

namespace Justrau\Orrery\Events;

/**
 * An operator action on the application's queue tables: the delete of one
 * waiting job (`delete`), the clear of a queue (`clear`), the run-now of a
 * delayed job (`run_now`) or the cancel of a batch (`cancel_batch`), from the
 * dashboard or (only `clear`) the command line.
 *
 * Actions\OperatorAudit dispatches it once per action that reached its
 * write, also when the write changed nothing (`result` says why), and
 * writes one `info` log line with the same facts. There is no stored
 * activity log: an application that wants one listens to this event.
 *
 * It carries no job value: `target` holds only names and ids (`uuid`,
 * `connection`, `queue`, `batch_id`), `counts` only numbers and flags.
 * `actor` is `Orrery::actor()` of the request (null when it names nobody),
 * or `cli` for a command. `at` is epoch seconds.
 */
class OperatorActionPerformed
{
    /**
     * @param  array<string, string|null>  $target
     * @param  array<string, int|bool|string|null>  $counts
     */
    public function __construct(
        public readonly string $action,
        public readonly array $target,
        public readonly ?string $actor,
        public readonly float $at,
        public readonly string $result,
        public readonly array $counts = [],
    ) {
    }
}
