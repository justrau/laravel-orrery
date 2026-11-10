<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use Justrau\Orrery\Tests\Scripts\Concerns\RunsScripts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Runner controls for the Docker opt-in. They never call Docker: every
 * PHPUnit subprocess runs with a fake `docker` first on PATH, which records
 * its arguments and exits 1.
 *
 * Without ORRERY_DOCKER_TESTS=1, no selection may reach a Docker call: not the
 * configured exclusions, not a command-line exclusion (which replaces them),
 * not repeated exclusions, not an explicit --group docker, not the class file.
 * These controls run on whatever PHPUnit is installed, so bin/matrix runs them
 * on PHPUnit 11.5 (lowest legs) and 12.5 (latest legs).
 */
#[Group('scripts')]
final class DockerOptInTest extends TestCase
{
    use RunsScripts;

    private const DOCKER_CLASS = 'Justrau\\Orrery\\Tests\\Scripts\\TestDbDockerTest';

    // One Docker test is enough to reach setUp, where the Docker calls start.
    private const ONE_TEST = 'TestDbDockerTest::test_up_with_an_occupied_port_fails_and_leaves_the_occupant_alone';

    private string $bin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bin = $this->makeTempDir('orrery-fakedocker-');
        file_put_contents($this->bin.'/docker', "#!/bin/sh\necho \"\$*\" >> ".escapeshellarg($this->bin.'/calls')."\nexit 1\n");
        chmod($this->bin.'/docker', 0755);
    }

    protected function tearDown(): void
    {
        $this->cleanUpScripts();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: bool}>
     */
    public static function selectionsWithoutTheOptIn(): iterable
    {
        yield 'the configured exclusions (composer test)' => [['--filter', self::ONE_TEST], false];
        yield 'the test:all exclusion' => [['--exclude-group', 'docker', '--filter', self::ONE_TEST], false];
        yield 'an ordinary exclusion' => [['--exclude-group', 'scripts', '--filter', self::ONE_TEST], true];
        yield 'repeated exclusions' => [['--exclude-group', 'scripts', '--exclude-group', 'workbench', '--filter', self::ONE_TEST], true];
        yield 'an explicit Docker selection' => [['--group', 'docker', '--filter', self::ONE_TEST], true];
        // The configured exclusions still apply to a file argument.
        yield 'the class file' => [['tests/Scripts/TestDbDockerTest.php'], false];
        yield 'the class file with an ordinary exclusion' => [['--exclude-group', 'scripts', 'tests/Scripts/TestDbDockerTest.php'], true];
    }

    /**
     * @param  list<string>  $selection
     */
    #[DataProvider('selectionsWithoutTheOptIn')]
    public function test_no_selection_calls_docker_without_the_opt_in(array $selection, bool $selectsDockerTests): void
    {
        $run = $this->phpunit($selection, []);

        self::assertFileDoesNotExist($this->bin.'/calls', 'a Docker test called docker without ORRERY_DOCKER_TESTS=1');

        $docker = array_filter($run['cases'], static fn (array $case): bool => $case['class'] === self::DOCKER_CLASS);

        if (! $selectsDockerTests) {
            // The filter leaves nothing else to run, so PHPUnit reports that.
            self::assertSame([], $docker, 'this selection runs Docker tests');
            self::assertStringContainsString('No tests executed', $run['stdout']);

            return;
        }

        self::assertSame(0, $run['exit'], $run['stdout'].$run['stderr']);

        // The selection reaches the Docker class, and every one of its tests skips.
        $expected = in_array('tests/Scripts/TestDbDockerTest.php', $selection, true) ? self::dockerTestCount() : 1;
        self::assertCount($expected, $docker);

        foreach ($docker as $case) {
            self::assertTrue($case['skipped'], "{$case['name']} ran without the opt-in");
        }

        self::assertStringContainsString('composer test:docker', $run['stdout']);
    }

    public function test_the_fake_docker_records_a_call_when_the_opt_in_is_set(): void
    {
        // Proves that the detection above works: with the opt-in, setUp calls
        // docker, the fake records it, and the test fails on its exit 1.
        $run = $this->phpunit(['--group', 'docker', '--filter', self::ONE_TEST], ['ORRERY_DOCKER_TESTS' => '1']);

        self::assertFileExists($this->bin.'/calls');
        self::assertStringStartsWith('version', (string) file_get_contents($this->bin.'/calls'));
        self::assertNotSame(0, $run['exit']);
    }

    public function test_the_composer_scripts_select_the_groups_explicitly(): void
    {
        $scripts = json_decode((string) file_get_contents(self::repoRoot().'/composer.json'), true)['scripts'];

        self::assertSame('@php vendor/bin/phpunit', $scripts['test']);
        // test:all can pass Composer's default 300 s process timeout.
        self::assertSame(['Composer\\Config::disableProcessTimeout', '@php vendor/bin/phpunit --exclude-group docker'], $scripts['test:all']);
        self::assertContains('@putenv ORRERY_DOCKER_TESTS=1', $scripts['test:docker']);
        self::assertContains('@php vendor/bin/phpunit --group docker', $scripts['test:docker']);

        // The fast loop: the configuration excludes the three slow groups.
        $config = simplexml_load_file(self::repoRoot().'/phpunit.xml.dist');
        self::assertNotFalse($config);
        $excluded = array_map('strval', $config->xpath('/phpunit/groups/exclude/group') ?: []);
        sort($excluded);
        self::assertSame(['docker', 'scripts', 'workbench'], $excluded);
    }

    /**
     * @param  list<string>  $selection
     * @param  array<string, string>  $env
     * @return array{exit: int, stdout: string, stderr: string, cases: list<array{class: string, name: string, skipped: bool}>}
     */
    private function phpunit(array $selection, array $env): array
    {
        $junit = $this->bin.'/junit.xml';
        $run = $this->runProcess(
            [PHP_BINARY, 'vendor/bin/phpunit', '--do-not-cache-result', '--display-skipped', '--log-junit', $junit, ...$selection],
            $this->scriptEnv(['PATH' => $this->bin.':'.getenv('PATH')] + $env),
            120.0,
        );
        $cases = [];

        if (file_exists($junit)) {
            $xml = simplexml_load_file($junit);
            self::assertNotFalse($xml);

            foreach ($xml->xpath('//testcase') ?: [] as $case) {
                $cases[] = ['class' => (string) $case['class'], 'name' => (string) $case['name'], 'skipped' => isset($case->skipped)];
            }
        }

        return ['exit' => $run['exit'], 'stdout' => $run['stdout'], 'stderr' => $run['stderr'], 'cases' => $cases];
    }

    private static function dockerTestCount(): int
    {
        $methods = (new ReflectionClass(self::DOCKER_CLASS))->getMethods(ReflectionMethod::IS_PUBLIC);

        return count(array_filter($methods, static fn (ReflectionMethod $method): bool => str_starts_with($method->getName(), 'test_')));
    }
}
