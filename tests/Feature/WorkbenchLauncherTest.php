<?php

namespace Justrau\Orrery\Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Every Composer script starts Testbench through workbench/bin/testbench, the only entry point
 * that pins the five bootstrap cache paths before Laravel reads them. WorkbenchBootstrapCacheTest
 * covers the launcher's behaviour. This test reads composer.json only, so it is not in the
 * `workbench` group.
 */
final class WorkbenchLauncherTest extends TestCase
{
    private const LAUNCHER = '@php workbench/bin/testbench ';

    public function test_every_composer_script_starts_testbench_through_the_launcher(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $scripts = $composer['scripts'];

        $commands = [];
        foreach ($scripts as $name => $script) {
            foreach ((array) $script as $command) {
                $commands[] = [$name, $command];

                $this->assertStringNotContainsString('vendor/bin/testbench', $command, sprintf('The Composer script %s starts Testbench without the launcher.', $name));
            }
        }

        $launched = array_values(array_map(
            static fn (array $entry): string => $entry[0].': '.substr($entry[1], strlen(self::LAUNCHER)),
            array_filter($commands, static fn (array $entry): bool => str_starts_with($entry[1], self::LAUNCHER)),
        ));

        $this->assertSame([
            'prepare: package:discover --ansi',
            'build: workbench:build --ansi',
            'serve: serve --ansi',
            'workbench:worker: workbench:slow --ansi',
            'workbench:worker: queue:work database --queue=slow --max-time=600 --ansi',
            'workbench:showcase:build: workbench:drop-database --ansi',
            'workbench:showcase:build: workbench:create-sqlite-db --ansi',
            'workbench:showcase:build: cache:clear --ansi',
            'workbench:showcase:build: migrate:fresh --force --seed --seeder=Workbench\\\\Database\\\\Seeders\\\\ShowcaseSeeder --ansi',
            'workbench:showcase: serve --ansi',
            'workbench:showcase-workers: workbench:showcase-workers --ansi',
        ], $launched);
        $this->assertSame(['@prepare'], $scripts['post-autoload-dump']);
        $this->assertContains('@build', (array) $scripts['serve']);
    }
}
