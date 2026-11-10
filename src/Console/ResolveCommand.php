<?php

namespace Justrau\Orrery\Console;

use Illuminate\Console\Command;
use Justrau\Orrery\Retry\OperationResolver;
use Justrau\Orrery\Retry\OperationStep;
use Justrau\Orrery\Retry\RetryHandler;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * orrery:resolve {id} or --all: the operator's resolve of an
 * operation, or of every operation that waits for an operator. It runs each
 * pending step inside an operator action (D17). It refuses an operation
 * whose target fingerprint differs (target_changed) and exits 1 when any
 * operation was refused, failed, is unknown, or stays open after its step
 * (a step that waits for the deadline, or for an operator again).
 */
#[AsCommand(name: 'orrery:resolve')]
class ResolveCommand extends Command
{
    /** @var string */
    protected $signature = 'orrery:resolve {id? : The operation id} {--all : Every operation that waits for an operator}';

    /** @var string */
    protected $description = 'Run the pending step of an Orrery retry or forget operation as an operator action';

    public function handle(OperationResolver $resolver): int
    {
        $id = $this->argument('id');
        $all = (bool) $this->option('all');

        if (($id === null) === ! $all) {
            $this->components->error('Give one operation id, or --all.');

            return self::INVALID;
        }

        $ids = $all ? $resolver->awaiting() : [(string) $id];
        $status = self::SUCCESS;

        if ($ids === []) {
            $this->components->info('No operation waits for an operator.');
        }

        foreach ($ids as $operation) {
            $result = preg_match('/^[0-9a-f]{32}$/D', $operation) === 1 ? $resolver->resolve($operation) : null;

            if ($result === null) {
                $this->components->error("No operation {$operation}.");
                $status = self::FAILURE;

                continue;
            }

            if ($result['code'] === RetryHandler::TARGET_CHANGED) {
                $this->components->error("Operation {$operation}: its target changed since its claim; restore the configuration, then resolve it.");
                $status = self::FAILURE;

                continue;
            }

            $line = "Operation {$operation} ({$result['action']}): {$result['state']}";

            if ($result['code'] !== null) {
                $line .= " ({$result['code']})";
            }

            if (in_array($result['step'], [OperationStep::UNDECIDED, OperationStep::BLOCKED], true)) {
                $this->components->warn($line);
                $status = self::FAILURE;
            } elseif (in_array($result['step'], [OperationStep::LATER, OperationStep::AWAITING], true)) {
                // The step ran, but the operation stays open: its outcome is not decided yet.
                $this->components->warn($line.'. It is still open: '.($result['step'] === OperationStep::LATER
                    ? 'its outcome is decided after its deadline, by the tick or by orrery:resolve then.'
                    : 'it still waits for an operator.'));
                $status = self::FAILURE;
            } else {
                $this->components->info($line);
            }
        }

        return $status;
    }
}
