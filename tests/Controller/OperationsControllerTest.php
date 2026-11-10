<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Justrau\Orrery\Orrery;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\ControllerTest;

/**
 * POST /api/operations/{id}/resolve: every JSON error carries its stable
 * `error` code beside the message (src/Http/CLAUDE.md, "Conventions").
 */
final class OperationsControllerTest extends ControllerTest
{
    /**
     * Rejects: a 404 with a message only, which the SPA cannot decide on.
     */
    public function test_an_unknown_operation_answers_not_found_with_its_code(): void
    {
        Orrery::operateUsing(static fn (): bool => true);

        foreach (['not-an-id', str_repeat('a', 32)] as $id) {
            $this->actingAs(new User)->postJson('/orrery/api/operations/'.$id.'/resolve')
                ->assertStatus(404)
                ->assertExactJson(['error' => 'not_found', 'message' => 'Not found.']);
        }
    }
}
