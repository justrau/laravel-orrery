<?php

namespace Justrau\Orrery\Console;

use Illuminate\Console\Command;
use Justrau\Orrery\Http\Bulk\BulkRefusal;
use Justrau\Orrery\Http\Bulk\BulkRun;
use Justrau\Orrery\Http\Bulk\BulkRuns;
use Justrau\Orrery\Http\Bulk\BulkSource;
use Justrau\Orrery\Lifecycle\JobStore;
use Justrau\Orrery\Retry\RetryResult;
use Justrau\Orrery\Retry\RetryService;
use Justrau\Orrery\Storage\PackageSession;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * orrery:retry: every retry goes through the operation ledger.
 *
 * - `orrery:retry <uuid>` retries the current occurrence of the job's mirror
 *   row (RetryService::retry(), source `cli`, actor `cli`).
 * - `orrery:retry --all` with the scope options freezes a bulk run (BulkRun):
 *   `--key` (repeatable) gives a groups source (with `--from` and `--to`,
 *   and `--connection` with `--queue` as its queue), `--batch` a batch
 *   source, anything else a list source (`--connection`, `--queue`,
 *   `--class`, `--tag`, `--from`, `--to`). It prints the total, the held
 *   count, the source and the ref, asks for a confirmation, then runs every
 *   page in this process (source `cli`). `--force` skips the question;
 *   without `--force` a run acts on nothing when the input is not
 *   interactive or STDIN is not a terminal (a pipe could answer).
 * - SIGINT stops the run after the current member.
 *
 * Exit codes, by precedence: 1 an error (also a refused freeze, a declined
 * confirmation, an unknown uuid, a scope option given with an empty value); 130 interrupted by SIGINT; 3 any
 * `uncertain`; 2 any `refused` or `not_member` (and for one uuid a refused,
 * not eligible or failed retry); 0 otherwise (`accepted` and `already_done`
 * only). The output lists the totals and the refused reasons.
 */
#[AsCommand(name: 'orrery:retry')]
class RetryCommand extends Command
{
    use RefusesWhileDisabled;

    public const ACTOR = 'cli';

    public const UNCERTAIN = 3;

    public const REFUSED = 2;

    public const INTERRUPTED = 130;

    /** @var string */
    protected $signature = 'orrery:retry
                            {uuid? : The uuid of the failed job}
                            {--all : Retry every failed job of the scope, in a bulk run}
                            {--connection= : With --all, the queue connection}
                            {--queue= : With --all, the queue (needs --connection)}
                            {--class= : With --all, the exact job class}
                            {--tag= : With --all, an exact tag}
                            {--batch= : With --all, the failed members of this batch}
                            {--key=* : With --all, a failure group key (repeatable; needs --from and --to)}
                            {--from= : With --all, the start of the failure window (epoch seconds)}
                            {--to= : With --all, the end of the failure window (epoch seconds)}
                            {--force : Retry without asking}';

    /** @var string */
    protected $description = 'Retry a failed queue job, or every failed job of a scope, through the operation ledger.';

    private bool $interrupted = false;

    public function handle(RetryService $retries, BulkRun $runs, PackageSession $sessions): int
    {
        $uuid = $this->argument('uuid');

        if ($this->option('all')) {
            if ($uuid !== null) {
                // Also an empty uuid: `orrery:retry "$ID" --all` with ID unset.
                $this->components->error('Give either a uuid or --all, not both.');

                return self::FAILURE;
            }

            return $this->retryAll($runs);
        }

        if (! is_string($uuid) || trim($uuid) === '') {
            $this->components->error('Give the uuid of a failed job, or --all with a scope.');

            return self::FAILURE;
        }

        return $this->retryOne($retries, $sessions, strtolower(trim($uuid)));
    }

    private function retryOne(RetryService $retries, PackageSession $sessions, string $uuid): int
    {
        $occurrence = $sessions->mirror()->table(JobStore::TABLE)->where('uuid', $uuid)->value('occurrence_id');

        if (! is_numeric($occurrence)) {
            $this->components->error('No failed job matches the given uuid.');

            return self::FAILURE;
        }

        $result = PackageSession::asOperator(static fn (): RetryResult => $retries->retry($uuid, (int) $occurrence, 'cli', self::ACTOR));
        $line = "{$uuid}: {$result->result}".($result->code === null ? '' : " ({$result->code})").($result->message === null ? '' : ": {$result->message}");

        return match (true) {
            $result->result === RetryResult::ACCEPTED => $this->done($line),
            // A held row: its operation decides it, as --all answers it (uncertain).
            $result->result === RetryResult::UNCERTAIN, $result->code === 'operation_in_progress' => $this->warned($line, self::UNCERTAIN),
            default => $this->warned($line, self::REFUSED),
        };
    }

    private function retryAll(BulkRun $runs): int
    {
        foreach (['connection', 'queue', 'class', 'tag', 'batch', 'from', 'to', 'key'] as $name) {
            // An unset shell variable would widen the scope: an empty value is refused, never read as absent.
            if (in_array('', (array) $this->option($name), true)) {
                $this->components->error("--{$name} is empty: give a value, or leave the option out.");

                return self::FAILURE;
            }
        }

        try {
            // No preview: it is job data, and an invalid redaction configuration must not stop the command.
            $freeze = $runs->freeze(BulkRun::RETRY, $this->source(), false);
        } catch (BulkRefusal $refusal) {
            $this->components->error($refusal->error.': '.$refusal->getMessage());

            return self::FAILURE;
        }

        $answer = $freeze['frozen'];
        $total = $answer['total'];
        $held = $freeze['held'];
        $this->components->twoColumnDetail('Failed jobs', $total['count'].($total['at_least'] ? ' or more' : ''));
        $this->components->twoColumnDetail('Held by an operation (not retried)', $held['count'].($held['at_least'] ? ' or more' : ''));
        $this->components->twoColumnDetail('Source', (string) json_encode($answer['source'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->components->twoColumnDetail('Run', (string) $answer['ref']);

        if (! $this->option('force')) {
            if (! $this->input->isInteractive() || ! $this->laravel->make(Terminal::class)->stdinIsTerminal()) {
                // A piped STDIN could answer the question.
                $this->components->error('Nothing retried: confirm on a terminal, or pass --force.');

                return self::FAILURE;
            }

            if (! $this->confirm('Retry these failed jobs?')) {
                $this->components->warn('Nothing retried.');

                return self::FAILURE;
            }
        }

        // A closure: Laravel resolves it only where signals are available, and
        // PHP without ext-pcntl has no SIGINT constant.
        $this->trap(static fn (): array => [SIGINT], function (): void {
            $this->interrupted = true;
        });

        $totals = array_fill_keys(BulkRuns::COUNTERS, 0);
        $reasons = [];
        $after = null;

        try {
            do {
                $page = $runs->run($answer['token'], $after, null, self::ACTOR, 'cli', fn (): bool => $this->interrupted);

                foreach ($page['totals'] as $outcome => $count) {
                    $totals[$outcome] = ($totals[$outcome] ?? 0) + $count;
                }

                foreach ((array) $page['refused_reasons'] as $code => $count) {
                    $reasons[(string) $code] = ($reasons[(string) $code] ?? 0) + (int) $count;
                }

                if (! $this->interrupted && self::stalled($page, $after)) {
                    $this->components->error('The run made no progress: a page ended without a new position. Its record: GET /api/bulk/'.$answer['ref'].'.');

                    return self::FAILURE;
                }

                $after = $page['next_after'];
            } while (! $page['done'] && ! $this->interrupted);
        } catch (BulkRefusal $refusal) {
            $this->components->error($refusal->error.': '.$refusal->getMessage());

            return self::FAILURE;
        } catch (Throwable $error) {
            report($error);
            $this->components->error('The run stopped with an error: '.get_class($error).'. Its record: GET /api/bulk/'.$answer['ref'].'.');

            return self::FAILURE;
        } finally {
            $this->untrap();
        }

        foreach ($totals as $outcome => $count) {
            $this->components->twoColumnDetail(str_replace('_', ' ', $outcome), (string) $count);
        }

        ksort($reasons);

        foreach ($reasons as $code => $count) {
            $this->components->twoColumnDetail('refused: '.$code, (string) $count);
        }

        return match (true) {
            $this->interrupted => self::INTERRUPTED,
            $totals['uncertain'] > 0 => self::UNCERTAIN,
            $totals['refused'] > 0 || $totals['not_member'] > 0 => self::REFUSED,
            default => self::SUCCESS,
        };
    }

    /**
     * Whether a page that is not done gives no new position (null, or the
     * cursor that the request sent): the run would loop for ever, or stop
     * with members left.
     *
     * @param  array<string, mixed>  $page
     */
    public static function stalled(array $page, ?string $after): bool
    {
        if (($page['done'] ?? false) === true) {
            return false;
        }

        $next = $page['next_after'] ?? null;

        return ! is_string($next) || $next === $after;
    }

    /**
     * The source of the scope options. An option that the chosen kind does
     * not use is refused (`invalid`), as BulkSource refuses an unknown key:
     * never dropped in silence.
     *
     * @return array<string, mixed>
     */
    private function source(): array
    {
        $option = fn (string $name): ?string => is_string($this->option($name)) && $this->option($name) !== '' ? $this->option($name) : null;
        $keys = array_values(array_filter((array) $this->option('key'), static fn (mixed $key): bool => is_string($key) && $key !== ''));
        $refuse = static function (array $unused, string $kind) use ($option): void {
            foreach ($unused as $name) {
                if ($option($name) !== null) {
                    throw BulkRefusal::invalid($name, "--{$name} is not an option of {$kind}.");
                }
            }
        };

        if ($keys !== []) {
            $refuse(['class', 'tag', 'batch'], 'a --key run');

            $connection = $option('connection');
            $queue = $option('queue');

            if (($connection === null) !== ($queue === null)) {
                throw BulkRefusal::invalid('queue', 'With --key, give --connection and --queue together, or neither.');
            }

            return ['kind' => BulkSource::GROUPS, 'queue' => $connection === null ? null : $connection.':'.$queue, 'from' => $option('from'), 'to' => $option('to'), 'keys' => $keys];
        }

        if ($option('batch') !== null) {
            $refuse(['connection', 'queue', 'class', 'tag', 'from', 'to'], 'a --batch run');

            return ['kind' => BulkSource::BATCH, 'batch_id' => $option('batch')];
        }

        return [
            'kind' => BulkSource::LIST,
            'connection' => $option('connection'),
            'queue' => $option('queue'),
            'class' => $option('class'),
            'tag' => $option('tag'),
            'from' => $option('from'),
            'to' => $option('to'),
        ];
    }

    private function done(string $line): int
    {
        $this->components->info($line);

        return self::SUCCESS;
    }

    private function warned(string $line, int $code): int
    {
        $this->components->warn($line);

        return $code;
    }
}
