<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Unit\Lifecycle;

use Justrau\Orrery\Lifecycle\ExceptionText;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The stored text of an exception.
 */
final class ExceptionTextTest extends TestCase
{
    /**
     * Rejects: the class name of an anonymous exception stored as PHP gives
     * it: a NUL byte (Postgres refuses it in a text column) and the path of
     * the server file that declared the class.
     */
    public function test_an_anonymous_exception_class_is_stored_without_its_file(): void
    {
        $error = new class('boom') extends RuntimeException
        {
        };
        self::assertStringContainsString("\0", $error::class, 'PHP names an anonymous class with a NUL byte and its file');

        $class = ExceptionText::columns($error)['exception_class'];

        self::assertSame('RuntimeException@anonymous', $class);
        self::assertSame('RuntimeException', ExceptionText::columns(new RuntimeException('boom'))['exception_class']);
    }
}
