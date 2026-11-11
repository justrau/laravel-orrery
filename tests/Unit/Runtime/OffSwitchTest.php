<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Unit\Runtime;

use Illuminate\Config\Repository;
use Justrau\Orrery\Runtime\OffSwitch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OffSwitchTest extends TestCase
{
    /**
     * Rejects: a switch that a blank ORRERY_ENABLED= or an unknown word turns off (the package must stay on unless it
     * is told otherwise), and one that ignores the false words of the environment ("0", "off", "no").
     * Still passes: a switch that also reads other words as false (only these values are pinned).
     */
    #[DataProvider('values')]
    public function test_only_an_explicit_false_turns_the_package_off(mixed $value, bool $enabled): void
    {
        self::assertSame($enabled, OffSwitch::fromConfig(new Repository(['orrery' => ['enabled' => $value]]))->on);
    }

    public function test_a_missing_key_keeps_the_package_on(): void
    {
        self::assertTrue(OffSwitch::fromConfig(new Repository(['orrery' => []]))->on);
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function values(): array
    {
        return [
            'true' => [true, true],
            'null' => [null, true],
            'blank' => ['', true],
            'spaces' => ['  ', true],
            'one' => ['1', true],
            'unknown word' => ['maybe', true],
            'false' => [false, false],
            'zero int' => [0, false],
            'zero string' => ['0', false],
            'false word' => ['false', false],
            'off' => [' off ', false],
            'no' => ['NO', false],
        ];
    }
}
