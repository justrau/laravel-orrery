<?php

namespace Justrau\Orrery\Retry;

use DateTimeInterface;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use RuntimeException;
use Throwable;

/**
 * The payload of a package retry (retry step 2), built from
 * the failed record's payload (or the mirror row's for the null failer).
 *
 * As Laravel's RetryCommand does, it resets `attempts` and refreshes
 * `retryUntil` from the job instance (application code, inside a catch;
 * a job whose model is gone keeps its retryUntil, as there). Then it sets the top-level keys `orreryGen` = the operation id
 * and `orreryRetry` = {cycle: k+1, operation: id}; nested members are never
 * touched. build() throws when the payload cannot be prepared.
 */
final class RetryPayload
{
    public function __construct(private readonly Application $app)
    {
    }

    /**
     * @throws RuntimeException
     */
    public function build(string $raw, int $cycle, string $operationId): string
    {
        // A plain catch, not the watcher: an operator action, so an open mirror breaker must
        // not refuse a payload that can be built. RetryService reports the failure.
        try {
            return $this->encode($raw, $cycle, $operationId);
        } catch (Throwable $error) {
            throw new RuntimeException('orrery: the retry payload cannot be prepared.', 0, $error);
        }
    }

    private function encode(string $raw, int $cycle, string $operationId): string
    {
        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            throw new RuntimeException('The payload of the failed record is not a JSON object.');
        }

        // RetryCommand::resetAttempts()
        if (isset($payload['attempts'])) {
            $payload['attempts'] = 0;
        }

        // RetryCommand::refreshRetryUntil()
        if (isset($payload['data']['command'])) {
            try {
                $instance = $this->instanceFromPayload($payload);
            } catch (ModelNotFoundException) {
                // As RetryCommand::refreshRetryUntil(): a job whose model is gone keeps its payload.
                $instance = null;
            }

            if (is_object($instance) && ! $instance instanceof \__PHP_Incomplete_Class && method_exists($instance, 'retryUntil')) {
                $retryUntil = $instance->retryUntil();
                $payload['retryUntil'] = $retryUntil instanceof DateTimeInterface ? $retryUntil->getTimestamp() : $retryUntil;
            }
        }

        $payload['orreryRetry'] = ['cycle' => $cycle, 'operation' => $operationId];
        $payload['orreryGen'] = $operationId;

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * RetryCommand::getInstanceFromPayload().
     *
     * @param  array<string, mixed>  $payload
     */
    private function instanceFromPayload(array $payload): mixed
    {
        $command = (string) $payload['data']['command'];

        if (str_starts_with($command, 'O:')) {
            return unserialize($command);
        }

        if ($this->app->bound(Encrypter::class)) {
            return unserialize($this->app->make(Encrypter::class)->decrypt($command));
        }

        throw new RuntimeException('Unable to extract job payload.');
    }
}
