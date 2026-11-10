<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Feature\Projection\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * A job with a synthetic secret in each position of its command:
 * public, protected and private properties, an array, a header
 * record, a URL password, a string over 64 KB with its secret at the end,
 * and a model (a ModelIdentifier). handle() fails with a secret in its
 * message when $fails is true.
 */
class CanaryJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public string $password;

    /** @var array<string, mixed> */
    public array $options;

    public string $note;

    public string $large;

    public string $label;

    protected string $apiKey;

    private string $secret;

    public function __construct(public ?CanaryUser $user = null, public bool $fails = false)
    {
        // Assigned here: SerializesModels leaves out a property that keeps its default value.
        $this->password = 'canary-public-password';
        $this->options = [
            'access_token' => 'canary-array-token',
            'headers' => [['name' => 'Authorization', 'value' => 'canary-header-value']],
            'kept' => 'plain option',
        ];
        $this->note = 'Connect to mysql://app:canary-url-password@db/app';
        $this->large = str_repeat('x', 70_000).' Bearer canary-large-end';
        $this->label = 'plain label';
        $this->apiKey = 'canary-protected-apikey';
        $this->secret = 'canary-private-secret';
    }

    public function handle(): void
    {
        if ($this->fails) {
            throw new RuntimeException('Login failed password=canary-exception-message for bob');
        }
    }
}
