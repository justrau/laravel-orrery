<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\ControllerTest;

/**
 * The number parameters of the read endpoints and of orrery:deployed are
 * checked with `$` and the D modifier, so a trailing newline is no number.
 */
final class TrailingNewlineTest extends ControllerTest
{
    /**
     * Rejects: patterns that accept a trailing newline (`$` without D), so "60\n" was read as 60.
     */
    public function test_a_number_with_a_trailing_newline_is_invalid(): void
    {
        TrimStrings::skipWhen(static fn (): bool => true);

        try {
            $this->actingAs(new User)->getJson('/orrery/api/markers?since=1790000000%0A')->assertStatus(422);
            $this->actingAs(new User)->getJson('/orrery/api/queues/history?connection=database&queue=default&minutes=60%0A')->assertStatus(422);
            $this->actingAs(new User)->getJson('/orrery/api/health?from=1790000000%0A')->assertStatus(422);
        } finally {
            TrimStrings::flushState();
        }

        $this->artisan('orrery:deployed', ['--window' => "600\n"])->assertExitCode(1);
    }
}
