<?php

/*
 * A stand-in for bin/test in the fixture tests of bin/matrix
 * (tests/Scripts/MatrixRunnerTest.php). It never starts PHPUnit.
 *
 * bin/matrix runs it in the leg copy as
 *   <leg PHP> fake-bin-test.php <engine> [--sqlite-file] [--no-provision] [--drop-database] --schema=<mode> --php=<binary> -- <PHPUnit arguments>
 * with ORRERY_MATRIX_RUN=<leg>/<run name>. It appends the call to the calls
 * file of the scenario (ORRERY_MATRIX_SCENARIO), then acts like PHPUnit:
 *
 * - `--list-tests-xml <file>`: writes a test list in the format of PHPUnit
 *   11.5 and 12.5 from the scenario's groups.
 * - Every other run: writes a JUnit file with the tests that the run selects
 *   (all passed; on in-memory SQLite the members of the scenario's
 *   shared-database group skip, as the documented engine skip), the identity
 *   line of the harness to ORRERY_IDENTITY_LOG (for the groups runs), a
 *   Runtime line and a PHPUnit summary, and exits like PHPUnit.
 *
 * The scenario's "runs" map changes single runs: its keys are fnmatch()
 * patterns of "<leg>/<run name>", and its values can set
 *   fail:      a list of {id, type (failure|error), text}
 *   skip:      a list of test ids that skip
 *   junit:     false (no JUnit file) or a string (the raw file content)
 *   junit_age: seconds: the JUnit file gets a modification time that far
 *              in the past, like a file of an earlier run
 *   identity:  values that replace fields of the identity record ("a.b"
 *              paths), or false (no identity line)
 *   runtime:   the PHP version of the Runtime line
 *   summary:   the summary line
 *   exit:      the exit status
 *   timeout:   a test id: like bin/test at its --timeout, it prints the
 *              timeout line that names that test on stderr and exits 124
 *   hold:      true: before it writes anything, it creates <calls>.held and
 *              waits up to 60 seconds for SIGTERM. On SIGTERM it records the signal in the calls file
 *              and exits 143, like bin/test after its cleanup.
 *   sleep:     seconds to wait before it writes anything (for durations)
 *
 * The first record of a call has its start time ("started", microtime) and
 * pid; when the process ends, an "end" record gets the run, the pid and the
 * end time, so a test can check the order and the durations.
 */

declare(strict_types=1);

$scenario = json_decode((string) file_get_contents((string) getenv('ORRERY_MATRIX_SCENARIO')), true);
$arguments = array_slice($argv, 1);
$engine = (string) array_shift($arguments);
$wrapper = [];
$php = null;

while ($arguments !== [] && $arguments[0] !== '--') {
    $argument = (string) array_shift($arguments);

    if (str_starts_with($argument, '--php=')) {
        $php = substr($argument, 6);
    } else {
        $wrapper[] = $argument;
    }
}

array_shift($arguments);
$phpunit = $arguments;
$run = (string) getenv('ORRERY_MATRIX_RUN');
$env = [];

foreach (getenv() as $name => $value) {
    if (preg_match('/^(ORRERY_TEST_|ORRERY_EXPECT_|ORRERY_IDENTITY_LOG$|ORRERY_DOCKER_TESTS$|ORRERY_SLOT_TOKEN$|COMPOSER_NO_BLOCKING$|XDEBUG_MODE$)/', (string) $name) === 1) {
        $env[$name] = $value;
    }
}

ksort($env);
file_put_contents($scenario['calls'], json_encode(['tool' => 'bin/test', 'run' => $run, 'engine' => $engine, 'wrapper' => $wrapper, 'php' => $php, 'phpunit' => $phpunit, 'cwd' => getcwd(), 'env' => $env, 'pid' => getmypid(), 'started' => microtime(true)], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
register_shutdown_function(static function () use ($scenario, $run): void {
    file_put_contents($scenario['calls'], json_encode(['tool' => 'end', 'run' => $run, 'pid' => getmypid(), 'ended' => microtime(true)], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
});

$option = static function (string $name) use ($phpunit): ?string {
    $position = array_search($name, $phpunit, true);

    return $position === false ? null : ($phpunit[$position + 1] ?? null);
};

$groups = $scenario['groups'];
$version = (string) getenv('ORRERY_EXPECT_PHP');

if (($list = $option('--list-tests-xml')) !== null) {
    $xml = "<?xml version=\"1.0\"?>\n<testSuite xmlns=\"https://xml.phpunit.de/testSuite\">\n <tests>\n";
    $all = array_values(array_unique(array_merge(...array_values($groups))));

    foreach ($all as $id) {
        $xml .= sprintf("  <testClass name=\"%s\" file=\"x.php\">\n   <testMethod id=\"%s\" name=\"%s\"/>\n  </testClass>\n", htmlspecialchars(explode('::', $id)[0]), htmlspecialchars($id), htmlspecialchars(explode('#', explode('::', $id)[1])[0]));
    }

    $xml .= " </tests>\n <groups>\n";

    foreach ($groups as $group => $ids) {
        if ($ids === []) {
            continue;
        }

        $xml .= sprintf("  <group name=\"%s\">\n", htmlspecialchars($group));

        foreach ($ids as $id) {
            $xml .= sprintf("   <test id=\"%s\"/>\n", htmlspecialchars($id));
        }

        $xml .= "  </group>\n";
    }

    file_put_contents($list, $xml." </groups>\n</testSuite>\n");
    echo "PHPUnit 12.5.0 by Sebastian Bergmann and contributors.\n\nRuntime:       PHP {$version}\n\nWrote list of tests that would have been run to {$list}\n";
    exit(0);
}

// The run name is <variant>.<engine>[-file].<kind>[.reuse], scripts, workbench or docker.
$name = explode('/', $run, 2)[1] ?? '';
$parts = explode('.', $name);
$kind = count($parts) >= 3 ? $parts[2] : $name;
$storage = str_ends_with($parts[1] ?? '', '-file') ? 'file' : ($engine === 'sqlite' ? 'memory' : 'server');

$override = [];

foreach ($scenario['runs'] ?? [] as $pattern => $change) {
    if (fnmatch($pattern, $run)) {
        $override = array_replace($override, $change);
    }
}

if (is_numeric($override['sleep'] ?? null)) {
    usleep((int) ((float) $override['sleep'] * 1_000_000));
}

if (is_string($override['timeout'] ?? null)) {
    $limit = '?';

    foreach ($wrapper as $argument) {
        if (str_starts_with($argument, '--timeout=')) {
            $limit = substr($argument, strlen('--timeout='));
        }
    }

    echo "PHPUnit 12.5.0 by Sebastian Bergmann and contributors.\n\nRuntime:       PHP {$version}\n\n";
    fwrite(STDERR, "bin/test: timeout: the run passed --timeout={$limit} s while it ran {$override['timeout']}; stopping it (exit 124)\n");
    exit(124);
}

if (($override['hold'] ?? false) === true) {
    $terminated = false;
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$terminated): void {
        $terminated = true;
    });
    touch($scenario['calls'].'.held');
    $deadline = microtime(true) + 60;

    while (! $terminated && microtime(true) < $deadline) {
        usleep(10_000);
    }

    file_put_contents($scenario['calls'], json_encode(['tool' => 'bin/test', 'run' => $run, 'signal' => $terminated ? 'SIGTERM' : 'none'], JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
    exit($terminated ? 143 : 1);
}

$selected = match ($kind) {
    'suite' => $groups['default'] ?? [],
    'groups' => array_merge($groups['harness'] ?? [], $groups['process'] ?? [], $groups['process-control'] ?? []),
    'scripts' => array_merge($groups['scripts'] ?? [], $groups['workbench'] ?? []),
    'workbench' => $groups['workbench'] ?? [],
    'docker' => $groups['docker'] ?? [],
    default => [],
};
// Like PHPUnit: a member of an excluded group does not run.
foreach ($phpunit as $index => $argument) {
    if ($argument === '--exclude-group') {
        $selected = array_diff($selected, $groups[$phpunit[$index + 1] ?? ''] ?? []);
    }
}

$selected = array_values(array_unique($selected));
// The documented engine skip: only the tests that need a shared database.
$sharedDatabase = $groups['shared-database'] ?? [];

$fails = [];

foreach ($override['fail'] ?? [] as $fail) {
    $fails[$fail['id']] = $fail;
}

$skips = $override['skip'] ?? [];

// The alt tests skip without bin/test --alt, which bin/matrix never passes.
if ($kind === 'docker') {
    $skips = [...$skips, ...($groups['alt'] ?? [])];
}
$cases = '';
$counts = ['tests' => 0, 'assertions' => 0, 'errors' => 0, 'failures' => 0, 'skipped' => 0];

foreach ($selected as $id) {
    [$class, $method] = explode('::', $id, 2);
    $dataSet = null;

    if (str_contains($method, '#')) {
        [$method, $dataSet] = explode('#', $method, 2);
    }

    $caseName = $dataSet === null ? $method : (ctype_digit($dataSet) ? "{$method} with data set #{$dataSet}" : "{$method} with data set \"{$dataSet}\"");
    $attributes = sprintf('name="%s" file="x.php" line="1" class="%s" classname="%s"', htmlspecialchars($caseName), htmlspecialchars($class), htmlspecialchars(str_replace('\\', '.', $class)));
    $counts['tests']++;

    if (in_array($id, $skips, true) || ($storage === 'memory' && in_array($id, $sharedDatabase, true))) {
        $counts['skipped']++;
        $cases .= "   <testcase {$attributes} assertions=\"0\" time=\"0.001\">\n    <skipped/>\n   </testcase>\n";

        continue;
    }

    $counts['assertions']++;

    if (isset($fails[$id])) {
        $type = $fails[$id]['type'] ?? 'failure';
        $counts[$type === 'error' ? 'errors' : 'failures']++;
        $cases .= sprintf("   <testcase %s assertions=\"1\" time=\"0.001\">\n    <%s type=\"Exception\">%s</%s>\n   </testcase>\n", $attributes, $type, htmlspecialchars($id."\n".$fails[$id]['text']."\n\n/x.php:1"), $type);

        continue;
    }

    $cases .= "   <testcase {$attributes} assertions=\"1\" time=\"0.001\"/>\n";
}

$junit = $option('--log-junit');

if ($junit !== null && ($override['junit'] ?? true) !== false) {
    $content = is_string($override['junit'] ?? null)
        ? $override['junit']
        : sprintf("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<testsuites>\n <testsuite name=\"fake\" tests=\"%d\" assertions=\"%d\" errors=\"%d\" failures=\"%d\" skipped=\"%d\" time=\"0.1\">\n%s </testsuite>\n</testsuites>\n", $counts['tests'], $counts['assertions'], $counts['errors'], $counts['failures'], $counts['skipped'], $cases);
    file_put_contents($junit, $content);

    if (isset($override['junit_age'])) {
        touch($junit, time() - (int) $override['junit_age']);
    }
}

$identityLog = getenv('ORRERY_IDENTITY_LOG');

if (is_string($identityLog) && $identityLog !== '' && ($override['identity'] ?? []) !== false) {
    $separate = getenv('ORRERY_TEST_MIRROR') === 'separate';
    $prefix = (string) getenv('ORRERY_TEST_PREFIX');
    $base = (string) getenv('ORRERY_TEST_DATABASE');
    $file = '/tmp/orrery-run.fake/orrery-sqlite-run_1234_0123abcd/primary.sqlite';
    $primary = match ($storage) {
        'memory' => ':memory:',
        'file' => $file,
        default => $base,
    };
    $mirror = match ($storage) {
        'memory' => ':memory:',
        'file' => $separate ? $file.'.mirror' : $file,
        default => $separate ? $base.'_mirror' : $base,
    };
    $kindOfEvidence = match ($storage) {
        'memory' => 'sqlite-memory',
        'file' => 'sqlite-file',
        default => 'endpoint-token',
    };
    $record = [
        'php' => $version,
        'primary' => ['driver' => $engine, 'database' => $primary, 'prefix' => $separate ? '' : $prefix, 'server_version' => $engine === 'mysql' ? '8.4.9' : ($engine === 'pgsql' ? '18.1' : '3.53.4'), 'evidence' => ['kind' => $kindOfEvidence]],
        'mirror' => ['driver' => $engine, 'database' => $mirror, 'prefix' => $prefix, 'evidence' => ['kind' => $kindOfEvidence]],
    ];

    foreach ($override['identity'] ?? [] as $path => $value) {
        $target = &$record;

        foreach (explode('.', $path) as $key) {
            $target = &$target[$key];
        }

        $target = $value;
        unset($target);
    }

    file_put_contents($identityLog, 'ORRERY-IDENTITY '.json_encode($record, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
}

$runtime = $override['runtime'] ?? $version;
echo "PHPUnit 12.5.0 by Sebastian Bergmann and contributors.\n\nRuntime:       PHP {$runtime}\n\n";

$failed = $counts['errors'] + $counts['failures'];
$summary = $override['summary'] ?? null;

if ($summary === null) {
    if ($failed === 0 && $counts['skipped'] === 0) {
        $summary = "OK ({$counts['tests']} tests, {$counts['assertions']} assertions)";
    } else {
        $summary = ($counts['errors'] > 0 ? "ERRORS!\n" : ($counts['failures'] > 0 ? "FAILURES!\n" : "OK, but some tests were skipped!\n"))
            ."Tests: {$counts['tests']}, Assertions: {$counts['assertions']}"
            .($counts['errors'] > 0 ? ", Errors: {$counts['errors']}" : '')
            .($counts['failures'] > 0 ? ", Failures: {$counts['failures']}" : '')
            .($counts['skipped'] > 0 ? ", Skipped: {$counts['skipped']}" : '')
            .'.';
    }
}

echo $summary."\n";

exit($override['exit'] ?? ($counts['errors'] > 0 ? 2 : ($counts['failures'] > 0 ? 1 : 0)));
