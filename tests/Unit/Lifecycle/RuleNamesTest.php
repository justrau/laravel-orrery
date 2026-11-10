<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Unit\Lifecycle;

use Justrau\Orrery\Lifecycle\Decision;
use Justrau\Orrery\Lifecycle\Rules;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The names of the lifecycle rules: each rule is a
 * method of Rules, Decision::$rule holds that method's name, and
 * docs/architecture/lifecycle-rules.md maps each old rule id to it.
 */
final class RuleNamesTest extends TestCase
{
    /** The old rule ids and their methods. */
    public const METHODS = [
        'P1' => ['push'],
        'P2' => ['bulkPush'],
        'R1' => ['reserve'],
        'R2' => ['reclaim'],
        'R3' => ['reserveReplacement'],
        'R4' => ['adoptRetryReservation'],
        'R5' => ['nativeRetry'],
        'R6' => ['reserveUnknown'],
        'R7' => ['reserveWithoutRow'],
        'R8' => ['fillWorker'],
        'L1' => ['release'],
        'E1' => ['exception'],
        'C1' => ['complete'],
        'C2' => ['adoptRetryCompletion'],
        'C3' => ['completeWithoutRow'],
        'F1' => ['fail', 'failWithoutRow', 'adoptRetryFailure'],
        'U1' => ['markUnknown'],
        'O' => ['olderCycle'],
    ];

    /**
     * Rejects: a doc table that misses an old id, maps it to another method, or names a method that Rules does not have.
     */
    public function test_the_doc_maps_every_old_id_to_a_method_of_rules(): void
    {
        $doc = (string) file_get_contents(dirname(__DIR__, 3).'/docs/architecture/lifecycle-rules.md');
        $mapped = [];

        foreach (explode("\n", $doc) as $line) {
            if (preg_match('/^\| (P1|P2|R[1-8]|L1|E1|C[1-3]|F1|U1|O) \| `(\w+)` \|/', $line, $match) === 1) {
                $mapped[$match[1]][] = $match[2];
            }
        }

        self::assertSame(self::METHODS, $mapped, 'one table row per old id and method, in the order of the spec');

        $rules = new ReflectionClass(Rules::class);

        foreach (array_merge(...array_values(self::METHODS)) as $method) {
            self::assertTrue($rules->hasMethod($method), "Rules has no method {$method}()");
        }
    }

    /**
     * Asserts that $rule names a mapped rule method or a Decision constant, never an old id such as R1.
     */
    public static function assertRuleName(string $rule): void
    {
        $names = array_merge(...array_values(self::METHODS));

        self::assertTrue(in_array($rule, [...$names, Decision::REJECTED, Decision::ACCOUNTED, Decision::HELD], true), "'{$rule}' is not the name of a rule method");
    }
}
