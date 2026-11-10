<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Unit\Observation;

use Justrau\Orrery\Lifecycle\Observation;
use Justrau\Orrery\Observation\PayloadIdentity;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The canonical uuid of a payload.
 */
final class PayloadIdentityTest extends TestCase
{
    private const UUID = '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d';

    /**
     * Rejects: a uuid pattern without the D modifier, whose `$` also matches
     * before a final newline, so a uuid with a trailing "\n" is taken as
     * canonical and mirrored with the newline (Postgres then refuses the
     * value of its uuid column). It would still pass a pattern that also
     * refuses upper case; the upper-case uuid is asserted to pass.
     */
    public function test_a_uuid_with_a_trailing_newline_is_not_canonical(): void
    {
        $canonical = new ReflectionMethod(Observation::class, 'canonicalUuid');

        self::assertNull(PayloadIdentity::uuidOf((string) json_encode(['uuid' => self::UUID."\n"])));
        self::assertNull($canonical->invoke(null, self::UUID."\n"));
        self::assertSame(self::UUID, PayloadIdentity::uuidOf((string) json_encode(['uuid' => strtoupper(self::UUID)])));
        self::assertSame(self::UUID, $canonical->invoke(null, strtoupper(self::UUID)));
    }
}
