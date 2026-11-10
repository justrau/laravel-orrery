<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use Justrau\Orrery\Tests\Support\Tia\Extension;
use Justrau\Orrery\Tests\Support\WorkerProcess;
use OrreryTia\Checks;
use OrreryTia\Command;
use OrreryTia\Literals;
use OrreryTia\MapBuilder;
use OrreryTia\PhpFile;
use OrreryTia\Selector;
use OrreryTia\Tree;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The test impact analysis of bin/tia (bin/lib/tia.php and the recorder in
 * tests/Support/Tia). The selector is a pure function of a map and the
 * current tree, so most tests build both by hand. The last tests make one
 * real recording of a small fixture suite under Xdebug.
 */
#[Group('scripts')]
final class TiaTest extends TestCase
{
    private ?string $fixture = null;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__, 2).'/bin/lib/tia.php';
    }

    protected function tearDown(): void
    {
        if ($this->fixture !== null) {
            (new Process(['rm', '-rf', $this->fixture]))->run();
        }

        parent::tearDown();
    }

    // ---- The hash and the analysis of a PHP file -------------------------

    /**
     * Rejects: a hash of the raw bytes (a comment or a blank line would select
     * tests), and a hash that drops the code too.
     * Still passes: a hash that also ignores a change inside a string literal
     * of whitespace only (it does not: strings are tokens).
     */
    public function test_the_hash_ignores_comments_and_whitespace_but_not_code(): void
    {
        $a = PhpFile::analyze("<?php\nclass A { public function f() { return 1; } }\n");
        $b = PhpFile::analyze("<?php\n// a comment\n/** doc */\nclass A {\n    public function f()\n    {\n        return 1; # end\n    }\n}\n");
        $c = PhpFile::analyze("<?php\nclass A { public function f() { return 2; } }\n");
        $d = PhpFile::analyze("<?php\nclass A { public function f() { return ' 1'; } }\n");
        $e = PhpFile::analyze("<?php\nclass A { public function f() { return '1'; } }\n");

        self::assertSame($a['hash'], $b['hash']);
        self::assertNotSame($a['hash'], $c['hash']);
        self::assertNotSame($d['hash'], $e['hash'], 'whitespace inside a string is code');
    }

    /**
     * Rejects: a line-blind hash for a file that reads __LINE__ (a comment
     * that adds a line changes the value).
     * Still passes: a hash that is line-sensitive for every file.
     */
    public function test_a_file_that_reads_line_numbers_hashes_its_lines(): void
    {
        $a = PhpFile::analyze("<?php\nfunction f() { return __LINE__; }\n");
        $b = PhpFile::analyze("<?php\n// moved\nfunction f() { return __LINE__; }\n");

        self::assertNotSame($a['hash'], $b['hash']);
    }

    /**
     * Rejects: a surface that keeps the method bodies (a body edit would
     * propagate to every referencing file), and one that drops signatures,
     * constants or property defaults.
     * Still passes: a surface that also keeps arrow function bodies (it does,
     * on purpose: conservative).
     */
    public function test_the_surface_drops_function_bodies_only(): void
    {
        $base = PhpFile::analyze("<?php\nclass A { const X = 1; public \$p = 2; public function f(int \$a = 3) { return \$a + 1; } }\n");
        $body = PhpFile::analyze("<?php\nclass A { const X = 1; public \$p = 2; public function f(int \$a = 3) { if (true) { return \$a + 2; } return 0; } }\n");
        $signature = PhpFile::analyze("<?php\nclass A { const X = 1; public \$p = 2; public function f(int \$a = 4) { return \$a + 1; } }\n");
        $constant = PhpFile::analyze("<?php\nclass A { const X = 2; public \$p = 2; public function f(int \$a = 3) { return \$a + 1; } }\n");
        $property = PhpFile::analyze("<?php\nclass A { const X = 1; public \$p = 5; public function f(int \$a = 3) { return \$a + 1; } }\n");
        $attribute = PhpFile::analyze("<?php\nclass A { const X = 1; public \$p = 2; public function f(#[Attr([1, 2])] int \$a = 3) { return \"{\$a}\"; } }\n");

        self::assertNotSame($base['hash'], $body['hash']);
        self::assertSame($base['surface'], $body['surface']);
        self::assertNotSame($base['surface'], $signature['surface']);
        self::assertNotSame($base['surface'], $constant['surface']);
        self::assertNotSame($base['surface'], $property['surface']);
        self::assertNotSame($base['surface'], $attribute['surface']);
    }

    /**
     * Rejects: a hash or a surface that drops the attribute token `#[`.
     */
    public function test_the_attribute_token_counts(): void
    {
        $with = PhpFile::analyze("<?php\n#[Attr]\nfunction f() {}\n");
        $without = PhpFile::analyze("<?php\nAttr]\nfunction f() {}\n");

        self::assertNotSame($with['hash'], $without['hash']);
        self::assertNotSame($with['surface'], $without['surface']);
    }

    /**
     * Rejects: an analysis without the #[Group] names of a test file (a new
     * workbench class would be routed to the engine run, where
     * phpunit.xml.dist excludes it).
     * Still passes: groups taken from any attribute named Group.
     */
    public function test_the_analysis_lists_the_group_attributes(): void
    {
        $a = PhpFile::analyze("<?php\nuse PHPUnit\\Framework\\Attributes\\Group;\n#[Group('workbench')]\nfinal class XTest {\n    #[\\PHPUnit\\Framework\\Attributes\\Group(\"docker\")]\n    public function test_a() { \$x = group('no'); }\n}\n");

        self::assertSame(['docker', 'workbench'], $a['groups']);
    }

    /**
     * Rejects: refs without string literals or qualified names, names not
     * resolved through the namespace and the imports (a short name would
     * match every class of that name), a declared list that takes Foo::class
     * or an anonymous class for a declaration, and propagating refs that
     * include plain type hints.
     * Still passes: refs that also hold keywords and member names (harmless:
     * refs are only matched against declared names).
     */
    public function test_the_analysis_lists_declarations_references_and_propagating_references(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Things;

use App\Other\Base;
use App\Models;

#[Marker(Limits::MAX)]
final class Thing extends Base implements \App\Contracts\Shape
{
    use Helpers;

    const DEFAULT = Status::Pending;

    const OTHER = Models\Limit::VALUE;

    public function __construct(private Clock $clock, private int $size = Sizes::SMALL)
    {
    }

    public function make(): object
    {
        $x = Thing::class;
        $y = new class { };

        return new Widget($this->clock, 'App\\Things\\Gadget');
    }
}

function helper(): void {}
PHP;
        $a = PhpFile::analyze($code);

        self::assertSame(['app\\things\\helper', 'app\\things\\thing'], $a['declares']);
        self::assertContains('app\\things\\widget', $a['refs']);
        self::assertContains('app\\things\\gadget', $a['refs'], 'a class name in a string literal');
        self::assertContains('app\\contracts\\shape', $a['refs']);
        self::assertContains('app\\things\\clock', $a['refs']);
        self::assertContains('app\\other\\base', $a['refs'], 'an imported name resolves through its use statement');
        self::assertNotContains('app\\things\\base', $a['refs']);
        self::assertSame(['app\\contracts\\shape', 'app\\models\\limit', 'app\\other\\base', 'app\\things\\helpers', 'app\\things\\limits', 'app\\things\\marker', 'app\\things\\sizes', 'app\\things\\status'], $a['surface_refs']);
        self::assertSame(['App\\Things\\Thing'], $a['classes']);
    }

    /**
     * Rejects: literals taken only relative to the root (a literal next to
     * the file is lost), separators, array keys and index lookups taken as
     * paths, and globs not cut to a prefix.
     * Still passes: a literal that names a file outside the repository (it is
     * kept and never matches).
     */
    public function test_path_literals_resolve_against_the_root_and_the_file_directory(): void
    {
        $code = "<?php\n\$a = dirname(__DIR__, 3).'/docs/architecture/rules.md';\n\$b = __DIR__.'/../../../stubs/X.stub';\n\$c = glob(\$r.'/src/Registration/Modules/*.php');\n\$d = \$x.'/'.\$y;\n\$e = \$r.DIRECTORY_SEPARATOR.'src';\n\$f = __DIR__.'/Fixtures/data.json';\n\$g = \$app['docs'];\n\$h = ['dist' => 1];\n\$i = ['stubs', 'bin'];\n\$j = \$tmp.'/../shared.json';\n\$k = dirname(__DIR__).'/Shared/y.json';\n\$l = dirname(__DIR__, 2).'/Other/z.json';\n";
        $a = PhpFile::analyze($code, 'tests/Feature/Some/ReaderTest.php');

        self::assertTrue(Literals::matches($a['literals'], 'docs/architecture/rules.md'));
        self::assertTrue(Literals::matches($a['literals'], 'stubs/X.stub'));
        self::assertTrue(Literals::matches($a['literals'], 'src/Registration/Modules/NewModule.php'));
        self::assertTrue(Literals::matches($a['literals'], 'src/Anything.php'), "the bare word 'src' is the source tree");
        self::assertTrue(Literals::matches($a['literals'], 'tests/Feature/Some/Fixtures/data.json'));
        self::assertFalse(Literals::matches($a['literals'], 'docs/other.md'), "an index (\$app['docs']) is no path");
        self::assertFalse(Literals::matches($a['literals'], 'dist/app.js'), 'an array key is no path');
        self::assertTrue(Literals::matches($a['literals'], 'stubs/X.php'), 'a list of directory names may build paths');
        self::assertFalse(Literals::matches($a['literals'], 'tests/Feature/shared.json'), 'only __DIR__ makes a literal relative to the file');
        self::assertTrue(Literals::matches($a['literals'], 'tests/Feature/Shared/y.json'), 'dirname(__DIR__) is the parent directory');
        self::assertTrue(Literals::matches($a['literals'], 'tests/Other/z.json'), 'dirname(__DIR__, 2) is two levels up');
        self::assertFalse(Literals::matches($a['literals'], 'tests/Feature/Some/ReaderTest.php'), "'/' alone is a separator, not the directory");
    }

    // ---- The selector ---------------------------------------------------

    /**
     * Rejects: a selector that runs every class that covers any file, or
     * that propagates a body change to referencing files.
     * Still passes: a selector that also adds classes for an unchanged file
     * (the other tests reject that).
     */
    public function test_a_body_change_selects_exactly_the_classes_that_ran_the_file(): void
    {
        $map = $this->map();
        $current = $this->tree($map, ['src/Store.php' => "<?php\nnamespace App;\nclass Store { const LIMIT = 5; public function put() { return 2; } }\n"]);

        $selection = Selector::select($map, [], $current);

        self::assertNull($selection->fallback);
        self::assertSame(['T\\StoreTest', 'T\\UserTest'], $selection->classNames());
    }

    /**
     * Rejects: a comment-only change of package code that selects tests, and
     * a comment-only change of a test file that selects nothing (a test can
     * read its own source).
     */
    public function test_a_comment_change_selects_only_a_changed_test_file(): void
    {
        $map = $this->map();
        $current = $this->tree($map, [
            'src/Store.php' => "<?php\nnamespace App;\n// note\nclass Store { const LIMIT = 5; public function put() { return 1; } }\n",
            'tests/OtherTest.php' => "<?php\nnamespace T;\n// note\nclass OtherTest { public function test_a() { \\App\\Other::run(); } }\n",
        ]);

        $selection = Selector::select($map, [], $current);

        self::assertNull($selection->fallback);
        self::assertSame(['T\\OtherTest'], $selection->classNames());
    }

    /**
     * Rejects: a surface change handled like a body change: UserTest reads
     * Store::LIMIT through User's constant expression and never runs
     * Store.php after its first load, and SubTest runs a subclass.
     * DeepTest runs Deep, a subclass of Sub, and never names Store: only the
     * transitive step reaches it.
     * Still passes: a propagation that also follows names in function
     * bodies of the surface files (broader, still bounded by the refs).
     */
    public function test_a_surface_change_follows_references_and_inheritance(): void
    {
        $map = $this->map();
        $current = $this->tree($map, ['src/Store.php' => "<?php\nnamespace App;\nclass Store { const LIMIT = 6; public function put() { return 1; } }\n"]);

        $selection = Selector::select($map, [], $current);

        self::assertNull($selection->fallback);
        self::assertSame(['T\\DeepTest', 'T\\HintTest', 'T\\StoreTest', 'T\\SubTest', 'T\\UserTest'], $selection->classNames());
        self::assertNotContains('T\\HintUserTest', $selection->classNames(), 'a type hint does not carry the surface on');
        self::assertNotContains('T\\ElsewhereTest', $selection->classNames(), 'Lib\\Store is another class');
    }

    /**
     * Rejects: a selector that falls back or selects nothing for a docs
     * change, and one that ignores the literal readers.
     */
    public function test_a_data_file_selects_the_classes_whose_files_name_its_path(): void
    {
        $map = $this->map();
        $current = $this->tree($map, ['docs/rules.md' => "changed\n"]);

        $selection = Selector::select($map, [], $current);

        self::assertNull($selection->fallback);
        self::assertSame(['T\\DocsTest'], $selection->classNames());
    }

    /**
     * Rejects: a selector that misses a recorded failure or a class whose
     * worker coverage was lost.
     */
    public function test_recorded_failures_always_run_and_incomplete_classes_run_on_package_changes(): void
    {
        $map = $this->map();
        $map['classes']['T\\OtherTest']['failed'] = true;
        $map['classes']['T\\DocsTest']['incomplete'] = true;

        $none = Selector::select($map, [], $this->tree($map, []));
        self::assertSame(['T\\OtherTest'], $none->classNames());

        $docs = Selector::select($map, [], $this->tree($map, ['README.md' => "x\n"]));
        self::assertSame(['T\\OtherTest'], $docs->classNames(), 'a docs change is no package change');

        $code = Selector::select($map, [], $this->tree($map, ['src/Store.php' => "<?php\nnamespace App;\nclass Store { const LIMIT = 5; public function put() { return 3; } }\n"]));
        self::assertSame(['T\\DocsTest', 'T\\OtherTest', 'T\\StoreTest', 'T\\UserTest'], $code->classNames());
    }

    /**
     * Rejects: a selector that keeps a narrow selection for a change it
     * cannot prove (each case names one).
     *
     * @return iterable<string, array{array<string, string|null>, string}>
     */
    public static function fallbacks(): iterable
    {
        yield 'unmapped PHP file' => [['src/Unused.php' => "<?php\nclass Unused { function f() { return 2; } }\n"], 'src/Unused.php'];
        yield 'new PHP file' => [['src/Fresh.php' => "<?php\nclass Fresh {}\n"], 'src/Fresh.php'];
        yield 'deleted mapped file' => [['src/Store.php' => null], 'src/Store.php'];
        yield 'composer.json' => [['composer.json' => "{}\n"], 'composer.json'];
        yield 'phpunit.xml.dist' => [['phpunit.xml.dist' => "<x/>\n"], 'phpunit.xml.dist'];
        yield 'tests/bootstrap.php' => [['tests/bootstrap.php' => "<?php\n\$x = 2;\n"], 'tests/bootstrap.php'];
        yield 'the recorder' => [['tests/Support/Tia/Recorder.php' => "<?php\n"], 'tests/Support/Tia/Recorder.php'];
        yield 'unclassified file' => [['.env.example' => "A=1\n"], '.env.example'];
        yield 'testbench.yaml' => [['testbench.yaml' => "providers: []\n"], 'testbench.yaml'];
        yield 'group without a recording' => [['workbench/app/Thing.php' => "<?php\n"], 'workbench'];
    }

    /**
     * @param  array<string, string|null>  $changes
     */
    #[DataProvider('fallbacks')]
    public function test_a_change_that_the_map_cannot_prove_falls_back(array $changes, string $named): void
    {
        $map = $this->map();
        $selection = Selector::select($map, [], $this->tree($map, $changes));

        self::assertNotNull($selection->fallback);
        self::assertStringContainsString($named, $selection->fallback);
    }

    /**
     * Rejects: a new test file that falls back or is not run, and a bin/
     * change without the scripts group or the docker notice.
     */
    public function test_new_test_files_run_themselves_and_scripts_select_their_group(): void
    {
        $map = $this->map();
        $new = Selector::select($map, [], $this->tree($map, ['tests/NewTest.php' => "<?php\nnamespace T;\nclass NewTest { public function test_a() {} }\n"]));

        self::assertNull($new->fallback);
        self::assertSame(['T\\NewTest'], $new->classNames());

        $bin = Selector::select($map, [], $this->tree($map, ['bin/lib/testdb.php' => "<?php\n// x\n\$a = 1;\n"]));
        self::assertNull($bin->fallback);
        self::assertSame(['T\\BareTest', 'T\\GuardTest', 'T\\ScriptTest'], $bin->classNames());
        self::assertStringContainsString('composer test:docker', implode("\n", $bin->notices));

        $js = Selector::select($map, [], $this->tree($map, ['resources/js/app.js' => "x\n"]));
        self::assertNull($js->fallback);
        self::assertStringContainsString('pnpm test', implode("\n", $js->notices));
    }

    /**
     * Rejects: a selector that runs the classes of the groups scripts and
     * workbench through their recorded files (their children load nearly
     * everything, so they would run for every change), and one that never
     * runs them for their own files, their own helpers or their rules.
     * Still passes: a selector that also runs them for a recorded failure
     * (it does, on purpose: a failure is retried until the next recording).
     */
    public function test_scripts_and_workbench_classes_run_only_through_their_rules(): void
    {
        $map = $this->map();

        $store = Selector::select($map, [], $this->tree($map, ['src/Store.php' => "<?php\nnamespace App;\nclass Store { const LIMIT = 5; public function put() { return 9; } }\n"]));
        self::assertSame(['T\\StoreTest', 'T\\UserTest'], $store->classNames());
        self::assertSame(['T\\ScriptTest'], array_keys($store->deferred), 'left to the chunk-end gate');

        $surface = Selector::select($map, [], $this->tree($map, ['src/Store.php' => "<?php\nnamespace App;\nclass Store { const LIMIT = 7; public function put() { return 1; } }\n"]));
        self::assertNotContains('T\\ScriptTest', $surface->classNames());

        $helper = Selector::select($map, [], $this->tree($map, ['tests/Support/ScriptHelper.php' => "<?php\nnamespace T\\Support;\nclass ScriptHelper { public static function run() { return 2; } }\n"]));
        self::assertSame(['T\\ScriptTest'], $helper->classNames(), 'a helper that only scripts classes ran');

        $own = Selector::select($map, [], $this->tree($map, ['tests/ScriptTest.php' => "<?php\nnamespace T;\nclass ScriptTest { public function test_a() { return 1; } }\n"]));
        self::assertSame(['T\\ScriptTest'], $own->classNames());

        $bin = Selector::select($map, [], $this->tree($map, ['bin/lib/testdb.php' => "<?php\n\$a = 2;\n"]));
        self::assertSame(['T\\BareTest', 'T\\GuardTest', 'T\\ScriptTest'], $bin->classNames());
    }

    /**
     * Rejects: a full fallback on another engine than in-memory SQLite that
     * runs only the default selection: phpunit.xml.dist excludes the groups
     * scripts and workbench there, so a bin/ change would run none of them.
     */
    public function test_every_fallback_also_runs_the_scripts_and_workbench_groups(): void
    {
        $map = $this->map();
        $map['engine'] = 'sqlite-file';
        $map['scope'] = 'default';
        $map['classes']['T\\ScriptTest']['groups'] = [];
        $map['classes']['T\\GuardTest']['groups'] = [];
        $map['classes']['T\\BareTest']['groups'] = [];
        $selection = Selector::select($map, [], $this->tree($map, ['bin/lib/matrix.php' => "<?php\n// x\n"]));

        self::assertNotNull($selection->fallback);

        $commands = Command::plan('sqlite-file', $selection, $selection->fallback, [], 'php');
        self::assertSame(['bin/test', 'sqlite', '--sqlite-file', '--'], $commands[0]);
        self::assertSame(['php', 'vendor/bin/phpunit', '--group', 'scripts', '--group', 'workbench', '--exclude-group', 'docker'], $commands[1]);

        $memory = Command::plan('sqlite', $selection, $selection->fallback, [], 'php');
        self::assertSame([['php', 'vendor/bin/phpunit', '--exclude-group', 'docker']], $memory);
    }

    /**
     * Rejects: routing by the recorded groups only: a new or regrouped class
     * of the group workbench would go to `bin/test <engine> -- --filter`,
     * where phpunit.xml.dist excludes it (zero tests, exit 0).
     * Still passes: a router that sends every class to both runs (the last
     * assertion rejects that for a class whose changed file left the group
     * workbench).
     */
    public function test_selected_classes_are_routed_by_their_current_groups(): void
    {
        $map = $this->map();
        $map['engine'] = 'sqlite-file';
        // StoreTest was recorded in the group workbench; its file now has no group.
        $map['classes']['T\\StoreTest']['groups'] = ['workbench'];
        $selection = Selector::select($map, [], $this->tree($map, [
            'tests/NewWorkbenchTest.php' => "<?php\nnamespace T;\nuse PHPUnit\\Framework\\Attributes\\Group;\n#[Group('workbench')]\nclass NewWorkbenchTest { public function test_a() {} }\n",
            'tests/NewPlainTest.php' => "<?php\nnamespace T;\nclass NewPlainTest { public function test_a() {} }\n",
            'tests/OtherTest.php' => "<?php\nnamespace T;\nuse PHPUnit\\Framework\\Attributes\\Group;\n#[Group('scripts')]\nclass OtherTest { public function test_a() { \\App\\Other::run(); } }\n",
            'tests/StoreTest.php' => "<?php\nnamespace T;\nclass StoreTest { public function test_a() { (new \\App\\Store)->put(); return 1; } }\n",
        ]));
        self::assertNull($selection->fallback);

        [$engine, $memory] = Command::plan('sqlite-file', $selection, null, [], 'php');
        self::assertSame(['bin/test', 'sqlite', '--sqlite-file', '--', '--filter'], array_slice($engine, 0, 5));
        self::assertSame(['php', 'vendor/bin/phpunit', '--exclude-group', 'docker', '--filter'], array_slice($memory, 0, 5));

        $inEngine = static fn (string $class): bool => preg_match($engine[5], $class.'::test_a') === 1;
        $inMemory = static fn (string $class): bool => preg_match($memory[5], $class.'::test_a') === 1;

        self::assertTrue($inMemory('T\\NewWorkbenchTest'), 'a new workbench class runs in memory (and on the engine, which excludes it)');
        self::assertTrue($inEngine('T\\NewPlainTest'));
        self::assertTrue($inMemory('T\\NewPlainTest'), 'a class that no map knows runs in both');
        self::assertTrue($inMemory('T\\OtherTest'), 'a class regrouped into scripts runs in memory');
        self::assertTrue($inEngine('T\\StoreTest'));
        self::assertFalse($inMemory('T\\StoreTest'), 'a changed test file routes by its groups now: it left the group workbench');
    }

    /**
     * Rejects: a deleted file that no class ran (an interface, a constant
     * class) selecting nothing: its users break on the deletion.
     */
    public function test_deleting_an_uncovered_declaration_selects_its_users(): void
    {
        $map = $this->map();
        $selection = Selector::select($map, [], $this->tree($map, ['src/Contract.php' => null]));

        self::assertNull($selection->fallback);
        self::assertSame(['T\\ImplTest'], $selection->classNames());
    }

    /**
     * Rejects: a change to a file of the docker tests without the notice
     * (bin/tia never runs the group docker), and a notice for every file
     * below a directory that a docker test names.
     */
    public function test_docker_test_files_and_their_helpers_give_the_docker_notice(): void
    {
        $map = $this->map();

        $alt = Selector::select($map, [], $this->tree($map, ['tests/Support/UsesAltServer.php' => "<?php\nnamespace T\\Support;\ntrait UsesAltServer {}\n"]));
        self::assertStringContainsString('composer test:docker', implode("\n", $alt->notices));

        $grouped = Selector::select($map, [], $this->tree($map, ['tests/Feature/SomeDockerProbeTest.php' => "<?php\nnamespace T;\nuse PHPUnit\\Framework\\Attributes\\Group;\n#[Group('docker')]\nclass SomeDockerProbeTest { public function test_a() {} }\n"]));
        self::assertStringContainsString('composer test:docker', implode("\n", $grouped->notices));

        $script = Selector::select($map, [], $this->tree($map, ['tests/Scripts/TestDbDockerTest.php' => "<?php\nnamespace T;\nclass TestDbDockerTest { public function test_a() {} }\n"]));
        self::assertStringContainsString('composer test:docker', implode("\n", $script->notices));

        $config = Selector::select($map, [], $this->tree($map, ['config/x.php' => "<?php\nreturn [];\n"]));
        self::assertSame([], $config->notices, "a docker test that names the directory 'config' is no reason");

        $testdb = Selector::select($map, [], $this->tree($map, ['bin/lib/testdb.php' => "<?php\n\$a = 3;\n"]));
        self::assertStringContainsString('composer test:docker', implode("\n", $testdb->notices));

        $plain = Selector::select($map, [], $this->tree($map, ['tests/StoreTest.php' => "<?php\nnamespace T;\nclass StoreTest { public function test_a() { return 2; } }\n"]));
        self::assertSame([], $plain->notices);
    }

    /**
     * Rejects: a gated class whose own test file names the changed path (an
     * invariant guard that scans src/, JsTestCoverageTest that reads
     * tests/js/) left to the chunk-end gate, and the same rule for a bare
     * top-level word ('tests': MatrixRunnerTest names a temporary tests/
     * directory and would run for every test file).
     */
    public function test_a_gated_class_that_names_a_package_path_in_its_own_file_runs(): void
    {
        $map = $this->map();
        $other = Selector::select($map, [], $this->tree($map, ['src/Other.php' => "<?php\nnamespace App;\nclass Other { public static function run() { return 3; } }\n"]));

        self::assertSame(['T\\GuardTest', 'T\\OtherTest'], $other->classNames());
        self::assertSame(['T\\ScriptTest'], array_keys($other->deferred));

        $js = Selector::select($map, [], $this->tree($map, ['tests/js/x.test.js' => "test('x', () => {});\n"]));
        self::assertSame(['T\\GuardTest'], $js->classNames(), 'a path below a top-level directory in its own file');
        self::assertSame(['T\\BareTest'], array_keys($js->deferred));

        $test = Selector::select($map, [], $this->tree($map, ['tests/OtherTest.php' => "<?php\nnamespace T;\nclass OtherTest { public function test_a() { \\App\\Other::run(); return 1; } }\n"]));
        self::assertNotContains('T\\BareTest', $test->classNames(), "the bare word 'tests' stays deferred");
        self::assertContains('T\\BareTest', array_keys($test->deferred));
    }

    /**
     * Rejects: a map whose recording ran on another variant (mirror, prefix,
     * SQLite journal mode, pooled Postgres sessions).
     */
    public function test_the_map_checks_fall_back_on_another_variant(): void
    {
        $meta = ['php' => '8.4', 'vendor' => 'v1', 'lock' => 'l1', 'head' => 'abc', 'variant' => ['mirror' => null, 'prefix' => null, 'sqlite_journal' => null, 'pg_pooled' => null]];
        $now = ['php' => '8.4', 'vendor' => 'v1', 'lock' => 'l1', 'variant' => $meta['variant']];

        self::assertNull(Checks::reason($meta, $now, 0, 50));
        self::assertStringContainsString('variant', (string) Checks::reason($meta, ['variant' => ['mirror' => 'separate'] + $meta['variant']] + $now, 0, 50));
        self::assertStringContainsString('variant', (string) Checks::reason($meta, ['variant' => ['prefix' => 'pfx_'] + $meta['variant']] + $now, 0, 50));

        // The defaults that bin/test spells out are the default variant.
        $saved = [getenv('ORRERY_TEST_MIRROR'), getenv('ORRERY_TEST_SQLITE_JOURNAL')];

        try {
            putenv('ORRERY_TEST_MIRROR=same');
            putenv('ORRERY_TEST_SQLITE_JOURNAL=wal');
            self::assertSame(['mirror' => null, 'sqlite_journal' => null], array_intersect_key(Checks::currentVariant(), ['mirror' => 1, 'sqlite_journal' => 1]));

            putenv('ORRERY_TEST_MIRROR=separate');
            putenv('ORRERY_TEST_SQLITE_JOURNAL=delete');
            self::assertSame(['mirror' => 'separate', 'sqlite_journal' => 'delete'], array_intersect_key(Checks::currentVariant(), ['mirror' => 1, 'sqlite_journal' => 1]));
        } finally {
            putenv($saved[0] === false ? 'ORRERY_TEST_MIRROR' : 'ORRERY_TEST_MIRROR='.$saved[0]);
            putenv($saved[1] === false ? 'ORRERY_TEST_SQLITE_JOURNAL' : 'ORRERY_TEST_SQLITE_JOURNAL='.$saved[1]);
        }
    }

    /**
     * Rejects: a map from a run that failed without a failed class (a
     * failure that the recorder did not see would never be retried).
     */
    public function test_a_recording_that_failed_without_a_failed_class_is_refused(): void
    {
        self::assertNull(Checks::recordingProblem(0, ['A' => ['failed' => false]]));
        self::assertNull(Checks::recordingProblem(1, ['A' => ['failed' => true]]));
        self::assertStringContainsString('exit 2', (string) Checks::recordingProblem(2, ['A' => ['failed' => false]]));
    }

    /**
     * Rejects: a SQLite-file or server recording in the reuse schema mode,
     * which credits the once-per-process migration to the first class.
     */
    public function test_file_and_server_recordings_use_the_fresh_schema_mode(): void
    {
        self::assertSame(['bin/test', 'sqlite', '--sqlite-file', '--schema=fresh', '--', '--extension', Command::EXTENSION], Command::recordCommand('sqlite-file', 'default', 'php'));
        self::assertSame(['bin/test', 'mysql', '--schema=fresh', '--', '--exclude-group', 'docker', '--extension', Command::EXTENSION], Command::recordCommand('mysql', 'all', 'php'));
        self::assertSame(['php', 'vendor/bin/phpunit', '--do-not-cache-result', '--exclude-group', 'docker', '--extension', Command::EXTENSION], Command::recordCommand('sqlite', 'all', 'php'));
    }

    /**
     * Rejects: a selection run that takes a --filter of its own: PHPUnit
     * keeps the last --filter, so it would replace the selection.
     */
    public function test_a_selection_refuses_a_filter_of_its_own(): void
    {
        foreach ([['--filter', 'X'], ['--filter=X']] as $arguments) {
            $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/bin/tia', '--dry-run', '--', ...$arguments], dirname(__DIR__, 2));
            $process->run();

            self::assertSame(64, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            self::assertStringContainsString('--filter', $process->getErrorOutput());
        }
    }

    /**
     * Rejects: a merge that ignores another engine's map, so a file that only
     * a server recording ran falls back or selects nothing.
     */
    public function test_another_engine_map_maps_its_own_files(): void
    {
        $map = $this->map();
        $server = $this->map();
        $server['engine'] = 'mysql';
        $server['files']['src/Unused.php']['tests'] = [array_search('T\\ServerTest', $server['class_list'], true)];
        $current = $this->tree($map, ['src/Unused.php' => "<?php\nclass Unused { function f() { return 3; } }\n"]);

        $selection = Selector::select($map, [$server], $current);

        self::assertNull($selection->fallback);
        self::assertSame(['T\\ServerTest'], $selection->classNames());
    }

    /**
     * Rejects: a check that trusts a map from another PHP minor version or
     * another vendor tree, or one older than the commit limit.
     */
    public function test_the_map_checks_fall_back_on_another_environment_or_an_old_map(): void
    {
        $meta = ['php' => '8.4', 'vendor' => 'v1', 'lock' => 'l1', 'head' => 'abc'];

        self::assertNull(Checks::reason($meta, ['php' => '8.4', 'vendor' => 'v1', 'lock' => 'l1'], 3, 50));
        self::assertStringContainsString('PHP', (string) Checks::reason($meta, ['php' => '8.5', 'vendor' => 'v1', 'lock' => 'l1'], 3, 50));
        self::assertStringContainsString('vendor', (string) Checks::reason($meta, ['php' => '8.4', 'vendor' => 'v2', 'lock' => 'l1'], 3, 50));
        self::assertStringContainsString('composer.lock', (string) Checks::reason($meta, ['php' => '8.4', 'vendor' => 'v1', 'lock' => 'l2'], 3, 50));
        self::assertStringContainsString('51 commits', (string) Checks::reason($meta, ['php' => '8.4', 'vendor' => 'v1', 'lock' => 'l1'], 51, 50));
        self::assertNotNull(Checks::reason($meta, ['php' => '8.4', 'vendor' => 'v1', 'lock' => 'l1'], null, 50), 'an unknown age is not a fresh map');
    }

    /**
     * Rejects: a recording that takes PHPUnit arguments: a --filter or a
     * --group would write a partial map, and a partial map misses the
     * classes that it did not run.
     */
    public function test_a_recording_refuses_phpunit_arguments(): void
    {
        $directory = sys_get_temp_dir().'/orrery-tia-args-'.bin2hex(random_bytes(4));
        $this->fixture = $directory;

        $process = new Process([PHP_BINARY, dirname(__DIR__, 2).'/bin/tia', 'record', '--map-dir='.$directory, '--', '--filter', 'SomeTest'], dirname(__DIR__, 2), ['XDEBUG_MODE' => 'coverage']);
        $process->run();

        self::assertSame(64, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString('no PHPUnit arguments', $process->getErrorOutput());
        self::assertDirectoryDoesNotExist($directory);
    }

    /**
     * Rejects: a WorkerProcess or worker CLI without the recording hook (a
     * worker's files would not reach the test that started it).
     * Still passes: a hook that is present but never called (the real
     * recording of a process test proves that; see CLAUDE.md).
     */
    public function test_the_worker_launch_and_the_worker_cli_carry_the_recording_hook(): void
    {
        $root = dirname(__DIR__, 2);
        $launch = new \ReflectionMethod(WorkerProcess::class, 'launch');
        $lines = array_slice((array) file($root.'/tests/Support/WorkerProcess.php'), $launch->getStartLine() - 1, $launch->getEndLine() - $launch->getStartLine() + 1);

        self::assertStringContainsString('Recorder::childEnvironment()),null,null);', self::code("<?php\n".implode('', $lines)));
        self::assertStringContainsString('ChildRecorder::startFromEnvironment();', self::code((string) file_get_contents($root.'/tests/Support/bin/artisan')));
        self::assertStringContainsString('ChildRecorder::flush();}$barrier->arrive(', self::code((string) file_get_contents($root.'/tests/Support/bin/artisan')));
        self::assertStringContainsString('->listen([WorkerStopping::class,JobTimedOut::class],staticfn():null=>ChildRecorder::flush())', self::code((string) file_get_contents($root.'/tests/Support/bin/artisan')), 'a worker that its timeout kills writes its files first');
    }

    /**
     * The code of a PHP source without comments and whitespace.
     */
    private static function code(string $source): string
    {
        $code = '';

        foreach (\PhpToken::tokenize($source) as $token) {
            if (! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                $code .= $token->text;
            }
        }

        return $code;
    }

    // ---- A real recording ------------------------------------------------

    /**
     * One real recording of a fixture suite under Xdebug, built into a map,
     * then selections against it. ChildTest runs WorkerOnly.php only in a
     * child process, started with the same two calls that WorkerProcess and
     * the worker CLI use.
     *
     * Rejects: a recorder that loses a child's files or gives them to another
     * class, one that misses the files of a data provider, one that
     * attributes per run instead of per class, a builder that gives a
     * killed child the files of other runs of its script (they do not show
     * what the killed run loaded), one that makes a class incomplete for a
     * killed child whose script includes no file, and a recorder that misses
     * a setUpBeforeClass() failure.
     */
    public function test_a_real_recording_maps_files_to_classes_including_child_processes(): void
    {
        if (! $this->coverageAvailable()) {
            self::markTestSkipped('Xdebug with the coverage mode is not available.');
        }

        $root = $this->makeFixture();
        $record = $root.'/.tia-record';
        mkdir($record);

        $process = new Process(
            [PHP_BINARY, dirname(__DIR__, 2).'/vendor/bin/phpunit', '--configuration', $root.'/phpunit.xml', '--do-not-cache-result', '--extension', Extension::class],
            $root,
            ['XDEBUG_MODE' => 'coverage', 'ORRERY_TIA_RECORD' => $record, 'ORRERY_TIA_ROOT' => $root],
            null,
            120.0,
        );
        $process->run();

        self::assertSame(2, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());

        $tree = Tree::hashes($root, Tree::scanDirectory($root));
        $map = MapBuilder::build([$record], $root, $tree, ['engine' => 'sqlite', 'scope' => 'all']);

        $tests = static fn (string $path): array => array_map(static fn (int $i): string => $map['class_list'][$i], $map['files'][$path]['tests']);

        self::assertSame(['Fx\\ATest', 'Fx\\ProviderTest'], $tests('src/A.php'));
        self::assertSame(['Fx\\BTest'], $tests('src/B.php'));
        self::assertSame(['Fx\\ChildTest'], $tests('src/WorkerOnly.php'), 'the child process file belongs to the class that started it');
        self::assertSame(['Fx\\ProviderTest'], $tests('src/Data.php'), 'the data provider runs before the tests');
        self::assertSame(['Fx\\ScrubbedTest'], $tests('src/ScrubOnly.php'), 'a child without the recording variables records for the running class');
        self::assertFalse($map['classes']['Fx\\ScrubbedTest']['incomplete']);
        self::assertTrue($map['classes']['Fx\\RepeatTest']['incomplete'], 'a killed child: the complete runs of its script do not show what it loaded');
        self::assertSame(['Fx\\RepeatTest', 'Fx\\WorkerRepeatTest'], $tests('src/RepeatOnly.php'));
        self::assertFalse($map['classes']['Fx\\LonelyTest']['incomplete'], 'a killed child whose script includes no file');
        self::assertTrue($map['classes']['Fx\\WorkerRepeatTest']['incomplete'], 'a killed worker: the same');
        self::assertSame(['Fx\\KillOnlyTest'], $tests('src/KillOnly.php'));
        self::assertTrue($map['classes']['Fx\\SetupFailTest']['failed'], 'a setUpBeforeClass() failure');
        self::assertTrue($map['classes']['Fx\\NoTraceTest']['incomplete'], 'a worker that left no trace');
        self::assertFalse($map['classes']['Fx\\WorkerLonelyTest']['incomplete'], 'a killed worker whose script includes no file left its .start file');
        self::assertSame(['Fx\\LonelyTest', 'Fx\\WorkerLonelyTest'], $tests('src/lonely.php'));
        self::assertTrue($map['classes']['Fx\\BTest']['failed']);
        self::assertFalse($map['classes']['Fx\\ATest']['failed'], 'a warning under @ is no failure');
        self::assertFalse($map['classes']['Fx\\ChildTest']['incomplete']);
        self::assertSame(2, $map['classes']['Fx\\ProviderTest']['tests']);

        file_put_contents($root.'/src/WorkerOnly.php', str_replace("'worker'", "'worker2'", (string) file_get_contents($root.'/src/WorkerOnly.php')));
        $selection = Selector::select($map, [], Tree::hashes($root, Tree::scanDirectory($root), $map));

        self::assertNull($selection->fallback);
        self::assertSame(['Fx\\BTest', 'Fx\\ChildTest', 'Fx\\NoTraceTest', 'Fx\\RepeatTest', 'Fx\\SetupFailTest', 'Fx\\WorkerRepeatTest'], $selection->classNames(), 'the change, the recorded failures and the incomplete classes');

        file_put_contents($root.'/src/WorkerOnly.php', str_replace("'worker2'", "'worker'", (string) file_get_contents($root.'/src/WorkerOnly.php')));
        file_put_contents($root.'/src/KillOnly.php', str_replace("'kill'", "'kill2'", (string) file_get_contents($root.'/src/KillOnly.php')));
        $killOnly = Selector::select($map, [], Tree::hashes($root, Tree::scanDirectory($root), $map));

        self::assertContains('Fx\\RepeatTest', $killOnly->classNames(), 'a file that only a killed child loaded selects its class');
        self::assertContains('Fx\\KillOnlyTest', $killOnly->classNames());
    }

    /**
     * Rejects: a child whose lost files pass unnoticed: a WorkerProcess child
     * (ORRERY_TIA_CHILD) and a child that only the context file attributes.
     */
    public function test_a_child_without_its_file_marks_its_class_incomplete(): void
    {
        if (! $this->coverageAvailable()) {
            self::markTestSkipped('Xdebug with the coverage mode is not available.');
        }

        $root = $this->makeFixture(killChild: true);
        $record = $root.'/.tia-record';
        mkdir($record);

        $process = new Process(
            [PHP_BINARY, dirname(__DIR__, 2).'/vendor/bin/phpunit', '--configuration', $root.'/phpunit.xml', '--do-not-cache-result', '--extension', Extension::class, '--filter', 'ChildTest|ScrubbedTest'],
            $root,
            ['XDEBUG_MODE' => 'coverage', 'ORRERY_TIA_RECORD' => $record, 'ORRERY_TIA_ROOT' => $root],
            null,
            120.0,
        );
        $process->run();

        $map = MapBuilder::build([$record], $root, Tree::hashes($root, Tree::scanDirectory($root)), ['engine' => 'sqlite', 'scope' => 'all']);

        self::assertTrue($map['classes']['Fx\\ChildTest']['incomplete'], 'the worker of WorkerProcess: '.$process->getOutput().$process->getErrorOutput());
        self::assertTrue($map['classes']['Fx\\ScrubbedTest']['incomplete'], 'a child that started (its .start file) and wrote nothing');
    }

    /**
     * Rejects: an extension that starts coverage, or strips XDEBUG_MODE from
     * the environment, when the recording variable is not set.
     * Still passes: an extension that subscribes to events and ignores them.
     */
    public function test_the_extension_is_inert_without_the_recording_variable(): void
    {
        if (! $this->coverageAvailable()) {
            self::markTestSkipped('Xdebug with the coverage mode is not available.');
        }

        $root = $this->makeFixture(inert: true);

        $process = new Process(
            [PHP_BINARY, dirname(__DIR__, 2).'/vendor/bin/phpunit', '--configuration', $root.'/phpunit.xml', '--do-not-cache-result', '--extension', Extension::class, '--filter', 'InertTest'],
            $root,
            ['XDEBUG_MODE' => 'coverage', 'ORRERY_TIA_RECORD' => false, 'ORRERY_TIA_ROOT' => $root],
            null,
            120.0,
        );
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        self::assertStringContainsString('OK (1 test', $process->getOutput());
    }

    // ---- Fixtures -------------------------------------------------------

    private function coverageAvailable(): bool
    {
        $probe = new Process([PHP_BINARY, '-r', 'echo function_exists("xdebug_info") && in_array("coverage", xdebug_info("mode"), true) ? "yes" : "no";'], null, ['XDEBUG_MODE' => 'coverage']);
        $probe->run();

        return trim($probe->getOutput()) === 'yes';
    }

    /**
     * A small suite: A and B are covered by one class each, Data only by a
     * data provider, WorkerOnly only by a child process of ChildTest.
     */
    private function makeFixture(bool $killChild = false, bool $inert = false): string
    {
        $root = sys_get_temp_dir().'/orrery-tia-'.bin2hex(random_bytes(6));
        $this->fixture = $root;
        $repo = dirname(__DIR__, 2);

        $files = [
            'phpunit.xml' => '<?xml version="1.0"?><phpunit bootstrap="tests/bootstrap.php" cacheDirectory=".phpunit.cache"><testsuites><testsuite name="F"><directory suffix="Test.php">tests</directory></testsuite></testsuites></phpunit>',
            // The fixture's autoloader is in vendor/, as Composer's is: the recorder leaves vendor/ out.
            'tests/bootstrap.php' => "<?php\nrequire '{$repo}/vendor/autoload.php';\nrequire __DIR__.'/../vendor/autoload.php';\n",
            'vendor/autoload.php' => "<?php\nspl_autoload_register(static function (string \$c): void { \$f = __DIR__.'/../src/'.\$c.'.php'; if (is_file(\$f)) { require \$f; } });\n",
            'src/A.php' => "<?php\nfinal class A { public static function value(): int { return 1; } }\n",
            'src/B.php' => "<?php\nfinal class B { public static function value(): int { return 2; } }\n",
            'src/Data.php' => "<?php\nfinal class Data { public static function rows(): array { return [[1], [1]]; } }\n",
            'src/WorkerOnly.php' => "<?php\nfinal class WorkerOnly { public static function run(): string { return 'worker'; } }\n",
            'src/child.php' => "<?php\nrequire_once '{$repo}/tests/Support/Tia/ChildRecorder.php';\n\\Justrau\\Orrery\\Tests\\Support\\Tia\\ChildRecorder::startFromEnvironment();\nrequire __DIR__.'/WorkerOnly.php';\necho WorkerOnly::run();\n".($killChild ? "posix_kill(getmypid(), SIGKILL);\n" : ''),
            'src/ScrubOnly.php' => "<?php\nfinal class ScrubOnly { public static function run(): string { return 'scrubbed'; } }\n",
            'src/scrubbed.php' => "<?php\nrequire __DIR__.'/ScrubOnly.php';\necho ScrubOnly::run();\n".($killChild ? "posix_kill(getmypid(), SIGKILL);\n" : ''),
            // A child without the ORRERY_* variables, as RunsScripts starts the bin scripts: the prepend file names its class.
            'tests/ScrubbedTest.php' => "<?php\nnamespace Fx;\nuse Symfony\\Component\\Process\\Process;\nfinal class ScrubbedTest extends \\PHPUnit\\Framework\\TestCase {\n    public function test_scrubbed(): void {\n        \$p = new Process([PHP_BINARY, __DIR__.'/../src/scrubbed.php'], null, ['ORRERY_TIA_CHILD' => false, 'ORRERY_TIA_DIRECT' => false, 'ORRERY_TIA_ROOT' => false, 'XDEBUG_MODE' => 'off']);\n        \$p->run();\n        self::assertStringStartsWith('scrubbed', \$p->getOutput(), \$p->getErrorOutput());\n    }\n}\n",
            'src/RepeatOnly.php' => "<?php\nfinal class RepeatOnly { public static function run(): string { return 'repeat'; } }\n",
            'src/repeat.php' => "<?php\nrequire __DIR__.'/RepeatOnly.php';\necho RepeatOnly::run();\nif ((\$argv[1] ?? '') === 'kill') { require __DIR__.'/KillOnly.php'; KillOnly::run(); posix_kill(getmypid(), SIGKILL); }\n",
            // Only the killed runs of repeat.php load KillOnly.php; KillOnlyTest runs it in-process.
            'src/KillOnly.php' => "<?php\nfinal class KillOnly { public static function run(): string { return 'kill'; } }\n",
            'tests/KillOnlyTest.php' => "<?php\nnamespace Fx;\nfinal class KillOnlyTest extends \\PHPUnit\\Framework\\TestCase { public function test_k(): void { require_once __DIR__.'/../src/KillOnly.php'; self::assertSame('kill', \\KillOnly::run()); } }\n",
            // A class whose setUpBeforeClass() throws: its failure carries only the class name.
            'tests/SetupFailTest.php' => "<?php\nnamespace Fx;\nfinal class SetupFailTest extends \\PHPUnit\\Framework\\TestCase { public static function setUpBeforeClass(): void { throw new \\RuntimeException('setup'); } public function test_s(): void { self::assertTrue(true); } }\n",
            // One complete run and one killed run of the same script.
            'tests/RepeatTest.php' => "<?php\nnamespace Fx;\nfinal class RepeatTest extends \\PHPUnit\\Framework\\TestCase {\n    public function test_repeat(): void {\n        foreach (['once', 'kill'] as \$mode) { self::assertStringStartsWith('repeat', (string) shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../src/repeat.php').' '.\$mode.' 2>&1')); }\n    }\n}\n",
            'src/lonely.php' => "<?php\necho 'lonely';\nposix_kill(getmypid(), SIGKILL);\n",
            // A killed child whose script includes no file: it ran only that file.
            'tests/LonelyTest.php' => "<?php\nnamespace Fx;\nfinal class LonelyTest extends \\PHPUnit\\Framework\\TestCase {\n    public function test_lonely(): void {\n        self::assertSame('lonely', (string) shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../src/lonely.php').' 2>/dev/null'));\n    }\n}\n",
            // The same with the worker handoff of WorkerProcess (ORRERY_TIA_CHILD and the direct flag).
            'tests/WorkerRepeatTest.php' => "<?php\nnamespace Fx;\nuse Justrau\\Orrery\\Tests\\Support\\Tia\\Recorder;\nuse Symfony\\Component\\Process\\Process;\nfinal class WorkerRepeatTest extends \\PHPUnit\\Framework\\TestCase {\n    public function test_worker_repeat(): void {\n        foreach (['once', 'kill'] as \$mode) { \$p = new Process([PHP_BINARY, __DIR__.'/../src/repeat.php', \$mode], null, Recorder::childEnvironment()); try { \$p->run(); } catch (\\Symfony\\Component\\Process\\Exception\\ProcessSignaledException) {} self::assertStringStartsWith('repeat', \$p->getOutput()); }\n    }\n}\n",
            // A worker handoff to a killed child whose script includes no file.
            'tests/WorkerLonelyTest.php' => "<?php\nnamespace Fx;\nuse Justrau\\Orrery\\Tests\\Support\\Tia\\Recorder;\nuse Symfony\\Component\\Process\\Process;\nfinal class WorkerLonelyTest extends \\PHPUnit\\Framework\\TestCase {\n    public function test_worker_lonely(): void { \$p = new Process([PHP_BINARY, __DIR__.'/../src/lonely.php'], null, Recorder::childEnvironment()); try { \$p->run(); } catch (\\Symfony\\Component\\Process\\Exception\\ProcessSignaledException) {} self::assertSame('lonely', \$p->getOutput()); }\n}\n",
            // A worker handoff whose child never recorded (no .start, no .json).
            'tests/NoTraceTest.php' => "<?php\nnamespace Fx;\nuse Justrau\\Orrery\\Tests\\Support\\Tia\\Recorder;\nfinal class NoTraceTest extends \\PHPUnit\\Framework\\TestCase {\n    public function test_no_trace(): void { self::assertIsArray(Recorder::childEnvironment()); }\n}\n",
            'tests/ATest.php' => "<?php\nnamespace Fx;\nfinal class ATest extends \\PHPUnit\\Framework\\TestCase { public function test_a(): void { @file_get_contents('/nonexistent/orrery-tia'); self::assertSame(1, \\A::value()); } }\n",
            'tests/BTest.php' => "<?php\nnamespace Fx;\nfinal class BTest extends \\PHPUnit\\Framework\\TestCase { public function test_b(): void { self::assertSame(3, \\B::value()); } }\n",
            'tests/ProviderTest.php' => "<?php\nnamespace Fx;\nfinal class ProviderTest extends \\PHPUnit\\Framework\\TestCase {\n    public static function rows(): array { return \\Data::rows(); }\n    #[\\PHPUnit\\Framework\\Attributes\\DataProvider('rows')]\n    public function test_p(int \$v): void { self::assertSame(\$v, \\A::value()); }\n}\n",
            'tests/ChildTest.php' => "<?php\nnamespace Fx;\nuse Justrau\\Orrery\\Tests\\Support\\Tia\\Recorder;\nuse Symfony\\Component\\Process\\Process;\nfinal class ChildTest extends \\PHPUnit\\Framework\\TestCase {\n    public function test_child(): void {\n        \$p = new Process([PHP_BINARY, __DIR__.'/../src/child.php'], null, Recorder::childEnvironment());\n        \$p->run();\n        self::assertStringStartsWith('worker', \$p->getOutput(), \$p->getErrorOutput());\n    }\n}\n",
        ];

        if ($inert) {
            $files['tests/InertTest.php'] = "<?php\nnamespace Fx;\nfinal class InertTest extends \\PHPUnit\\Framework\\TestCase { public function test_inert(): void { self::assertSame('coverage', getenv('XDEBUG_MODE')); self::assertSame([], xdebug_get_code_coverage()); } }\n";
        }

        foreach ($files as $path => $content) {
            if (! is_dir(dirname($root.'/'.$path))) {
                mkdir(dirname($root.'/'.$path), 0777, true);
            }

            file_put_contents($root.'/'.$path, $content);
        }

        return $root;
    }

    /**
     * A hand-made map: Store (a class with a constant) is run by StoreTest;
     * User reads Store::LIMIT in a constant expression and is run by
     * UserTest; Sub extends Store; Hint takes a Store as a type hint; Other
     * is run by OtherTest; Deep extends Sub and is run by DeepTest;
     * DocsTest reads docs/rules.md; Unused is in the
     * tree but no test ran it.
     *
     * @return array<string, mixed>
     */
    private function map(): array
    {
        $sources = [
            'src/Store.php' => "<?php\nnamespace App;\nclass Store { const LIMIT = 5; public function put() { return 1; } }\n",
            'src/User.php' => "<?php\nnamespace App;\nclass User { const MAX = Store::LIMIT * 2; public function go() { return self::MAX; } }\n",
            'src/Sub.php' => "<?php\nnamespace App;\nclass Sub extends Store { public function more() { return 3; } }\n",
            'src/Deep.php' => "<?php\nnamespace App;\nclass Deep extends Sub { public function deeper() { return 4; } }\n",
            'src/Hint.php' => "<?php\nnamespace App;\nclass Hint { public function __construct(private Store \$store) {} public function go() { return 1; } }\n",
            'src/HintUser.php' => "<?php\nnamespace App;\nclass HintUser { public function go(Hint \$hint) { return \$hint->go(); } }\n",
            'src/Other.php' => "<?php\nnamespace App;\nclass Other { public static function run() { return 1; } }\n",
            'src/Unused.php' => "<?php\nclass Unused { function f() { return 1; } }\n",
            'src/Contract.php' => "<?php\nnamespace App;\ninterface Contract { public function work(); }\n",
            'src/Impl.php' => "<?php\nnamespace App;\nclass Impl implements Contract { public function work() { return 1; } }\n",
            'tests/ImplTest.php' => "<?php\nnamespace T;\nclass ImplTest { public function test_a() { (new \\App\\Impl)->work(); } }\n",
            // A scripts guard that reads src/Other.php and tests/js/x.test.js in its own file.
            'tests/GuardTest.php' => "<?php\nnamespace T;\nclass GuardTest { public function test_a() { file_get_contents(dirname(__DIR__).'/src/Other.php'); file_get_contents(dirname(__DIR__).'/tests/js/x.test.js'); } }\n",
            // A scripts class that names only the bare top-level word 'tests'.
            'tests/BareTest.php' => "<?php\nnamespace T;\nclass BareTest { public function test_a() { mkdir(\$tmp.DIRECTORY_SEPARATOR.'tests'); } }\n",
            // A docker test that names the directories config/ and bin/lib.
            'tests/DockerNamingTest.php' => "<?php\nnamespace T;\nuse PHPUnit\\Framework\\Attributes\\Group;\n#[Group('docker')]\nclass DockerNamingTest { public function test_a() { copy(\$root.DIRECTORY_SEPARATOR.'config', __DIR__.'/../bin/lib'); } }\n",
            'src/Elsewhere.php' => "<?php\nnamespace Lib;\nclass Elsewhere { public function go(Store \$s) { return Store::LIMIT; } }\n",
            'tests/StoreTest.php' => "<?php\nnamespace T;\nclass StoreTest { public function test_a() { (new \\App\\Store)->put(); } }\n",
            'tests/UserTest.php' => "<?php\nnamespace T;\nclass UserTest { public function test_a() { (new \\App\\User)->go(); (new \\App\\Store)->put(); } }\n",
            'tests/SubTest.php' => "<?php\nnamespace T;\nclass SubTest { public function test_a() { (new \\App\\Sub)->more(); } }\n",
            'tests/DeepTest.php' => "<?php\nnamespace T;\nclass DeepTest { public function test_a() { (new \\App\\Deep)->deeper(); } }\n",
            'tests/HintTest.php' => "<?php\nnamespace T;\nclass HintTest { public function test_a() { (new \\App\\Hint(new \\App\\Sub))->go(); } }\n",
            'tests/HintUserTest.php' => "<?php\nnamespace T;\nclass HintUserTest { public function test_a(\\App\\HintUser \$u) { \$u->go(\$this->hint()); } }\n",
            'tests/OtherTest.php' => "<?php\nnamespace T;\nclass OtherTest { public function test_a() { \\App\\Other::run(); } }\n",
            'tests/DocsTest.php' => "<?php\nnamespace T;\nclass DocsTest { public function test_a() { file_get_contents(dirname(__DIR__).'/docs/rules.md'); } }\n",
            'tests/ScriptTest.php' => "<?php\nnamespace T;\nclass ScriptTest { public function test_a() {} }\n",
            'tests/Support/ScriptHelper.php' => "<?php\nnamespace T\\Support;\nclass ScriptHelper { public static function run() { return 1; } }\n",
            'docs/rules.md' => "rules\n",
            'README.md' => "readme\n",
            'composer.json' => "{\"a\": 1}\n",
            'phpunit.xml.dist' => "<phpunit/>\n",
            'tests/bootstrap.php' => "<?php\n\$x = 1;\n",
            'tests/Support/Tia/Recorder.php' => "<?php\n// recorder\n",
            'bin/lib/testdb.php' => "<?php\n\$a = 1;\n",
            'resources/js/app.js' => "app\n",
        ];

        $runs = [
            'T\\StoreTest' => ['src/Store.php', 'tests/StoreTest.php'],
            'T\\UserTest' => ['src/User.php', 'src/Store.php', 'tests/UserTest.php'],
            'T\\SubTest' => ['src/Sub.php', 'tests/SubTest.php'],
            'T\\DeepTest' => ['src/Deep.php', 'tests/DeepTest.php'],
            'T\\ElsewhereTest' => ['src/Elsewhere.php'],
            'T\\ImplTest' => ['src/Impl.php', 'tests/ImplTest.php'],
            'T\\GuardTest' => ['tests/GuardTest.php'],
            'T\\BareTest' => ['tests/BareTest.php'],
            'T\\HintTest' => ['src/Hint.php', 'src/Sub.php', 'tests/HintTest.php'],
            'T\\HintUserTest' => ['src/HintUser.php', 'tests/HintUserTest.php'],
            'T\\OtherTest' => ['src/Other.php', 'tests/OtherTest.php'],
            'T\\DocsTest' => ['tests/DocsTest.php'],
            // A scripts class whose children load package files and a helper of its own.
            'T\\ScriptTest' => ['tests/ScriptTest.php', 'src/Store.php', 'src/Other.php', 'tests/Support/ScriptHelper.php'],
            'T\\ServerTest' => [],
        ];

        $classList = array_keys($runs);
        $files = [];

        foreach ($sources as $path => $content) {
            $files[$path] = [
                'raw' => sha1($content),
                'php' => Tree::isPhp($path) ? PhpFile::analyze($content, $path) : null,
                'tests' => [],
            ];
        }

        foreach ($runs as $class => $paths) {
            foreach ($paths as $path) {
                $files[$path]['tests'][] = array_search($class, $classList, true);
            }
        }

        $classes = [];

        foreach ($classList as $class) {
            $file = 'tests/'.substr($class, 2).'.php';
            $classes[$class] = [
                'file' => $file,
                'groups' => in_array($class, ['T\\ScriptTest', 'T\\GuardTest', 'T\\BareTest'], true) ? ['scripts'] : ['default'],
                'tests' => 1,
                'failed' => false,
                'incomplete' => false,
            ];
        }

        return [
            'version' => MapBuilder::VERSION,
            'engine' => 'sqlite',
            'scope' => 'all',
            'class_list' => $classList,
            'classes' => $classes,
            'files' => $files,
            'sources' => $sources,
        ];
    }

    /**
     * The current tree: the map's sources with $changes applied (null
     * deletes a file), hashed and analysed as bin/tia does.
     *
     * @param  array<string, mixed>  $map
     * @param  array<string, string|null>  $changes
     * @return array<string, array{raw: string, php: array<string, mixed>|null}>
     */
    private function tree(array $map, array $changes): array
    {
        $contents = array_replace($map['sources'], $changes);
        $tree = [];

        foreach ($contents as $path => $content) {
            if ($content === null) {
                continue;
            }

            $raw = sha1($content);
            $unchanged = ($map['files'][$path]['raw'] ?? null) === $raw;
            $tree[$path] = [
                'raw' => $raw,
                'php' => Tree::isPhp($path) ? ($unchanged ? $map['files'][$path]['php'] : PhpFile::analyze($content, $path)) : null,
            ];
        }

        return $tree;
    }
}
