<?php

namespace Workbench\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Workbench\App\Exceptions\WorkbenchJobFailed;
use Workbench\App\Support\Canaries;

/**
 * A failing job with a synthetic secret in every position (the
 * canaries): public, protected and private properties, an array with the key forms, a header
 * record, a webhook URL, strings over 64 KB whose secrets are at their ends, and the exception
 * message and its context. The seeder adds the Context values and a top-level payload key.
 */
class SecretsJob implements ShouldQueue
{
    use Queueable;

    public string $password;

    protected string $apiToken;

    private string $clientSecret;

    /** @var array<string, mixed> */
    public array $credentials;

    /** @var list<array{name: string, value: string}> */
    public array $headers;

    public string $webhook_url;

    public string $longNote;

    public string $longUrlNote;

    /**
     * Every value is set here, not as a property default: Laravel leaves a property that still
     * has its default value out of the serialized command.
     */
    public function __construct(public int $number)
    {
        $this->password = Canaries::PUBLIC_PROPERTY;
        $this->apiToken = Canaries::PROTECTED_PROPERTY;
        $this->clientSecret = Canaries::PRIVATE_PROPERTY;
        $this->credentials = [
            'stripe' => ['secret_key' => Canaries::ARRAY_SECRET_KEY, 'stripe_secret' => Canaries::STRIPE_SECRET],
            'db_password' => Canaries::DB_PASSWORD,
            'aws_secret_access_key' => Canaries::AWS_SECRET_ACCESS_KEY,
            'auth_token' => Canaries::AUTH_TOKEN,
            'webhook_secret' => Canaries::WEBHOOK_SECRET,
            'classname' => 'App\Billing\Invoice',
        ];
        $this->headers = [
            ['name' => 'Accept', 'value' => 'application/json'],
            ['name' => 'Authorization', 'value' => 'Bearer '.Canaries::HEADER],
        ];
        $this->webhook_url = Canaries::WEBHOOK;
        $this->longNote = str_repeat('a long free-text note of the partner import ', 1600).'api_key='.Canaries::LONG_TAIL;
        $this->longUrlNote = Canaries::longUrlNote();
    }

    public function handle(): void
    {
        // Read once, so a static analyser sees the private property used.
        $secret = $this->clientSecret.$this->apiToken;

        // The exception's context (Laravel's context() of the exception) carries canaries too.
        throw new WorkbenchJobFailed(Canaries::exceptionMessage(), [
            'number' => $this->number,
            'has_secret' => $secret !== '',
            'api_token' => Canaries::EXCEPTION_CONTEXT,
            'headers' => [['name' => 'Authorization', 'value' => 'Bearer '.Canaries::EXCEPTION_CONTEXT_HEADER]],
        ]);
    }
}
