<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use Justrau\Orrery\Tests\Scripts\Concerns\RunsScripts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * bin/check-assets in a temporary git checkout, with a fake node and a fake
 * npm first on PATH. The fake npm records its calls, and its `run build`
 * writes dist/app.js (ORRERY_FAKE_BUILD) and, with ORRERY_FAKE_EXTRA, one more
 * file in dist/. The real build is checked by running bin/check-assets itself.
 *
 * It is in the group scripts: `composer test` skips it, `composer test:all`
 * runs it.
 */
#[Group('scripts')]
final class AssetsCheckTest extends TestCase
{
    use RunsScripts;

    private string $repo;

    private string $tools;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = $this->makeTempDir('orrery-assets-');
        $this->tools = $this->makeTempDir('orrery-assets-tools-');

        mkdir($this->repo.'/bin');
        mkdir($this->repo.'/dist');
        copy(self::repoRoot().'/bin/check-assets', $this->repo.'/bin/check-assets');
        chmod($this->repo.'/bin/check-assets', 0755);
        file_put_contents($this->repo.'/.nvmrc', "24\n");
        file_put_contents($this->repo.'/package.json', "{\"engines\": {\"node\": \"^24.0.0\"}}\n");
        file_put_contents($this->repo.'/package-lock.json', "{\"lockfileVersion\": 3}\n");
        file_put_contents($this->repo.'/dist/app.js', "built\n");
        file_put_contents($this->repo.'/dist/app.css', "css\n");
        $this->git(['init', '--quiet']);
        $this->git(['add', '--all']);
        $this->commit('fixture');

        file_put_contents($this->tools.'/node', "#!/bin/sh\necho \"v\${ORRERY_FAKE_NODE:-24.20.0}\"\n");
        file_put_contents($this->tools.'/npm', <<<'SH'
            #!/bin/sh
            echo "$*" >>"$ORRERY_FAKE_NPM_LOG"
            case "$1" in
                --version) echo 11.19.0 ;;
                ci) exit "${ORRERY_FAKE_NPM_CI_EXIT:-0}" ;;
                run)
                    if [ -n "${ORRERY_FAKE_BUILD_BYTES:-}" ]; then
                        head -c "$ORRERY_FAKE_BUILD_BYTES" /dev/zero | tr '\0' a >dist/app.js
                    else
                        printf '%s\n' "${ORRERY_FAKE_BUILD:-built}" >dist/app.js
                    fi
                    if [ -n "${ORRERY_FAKE_EXTRA:-}" ]; then
                        echo extra >"dist/$ORRERY_FAKE_EXTRA"
                    fi
                    if [ -n "${ORRERY_FAKE_UNREADABLE_APP:-}" ]; then
                        chmod 000 dist/app.js
                    fi
                    ;;
            esac

            SH);
        chmod($this->tools.'/node', 0755);
        chmod($this->tools.'/npm', 0755);
    }

    protected function tearDown(): void
    {
        $this->cleanUpScripts();
        parent::tearDown();
    }

    public function test_a_build_that_reproduces_dist_passes(): void
    {
        $run = $this->checkAssets();

        self::assertSame(0, $run['exit'], $run['stdout'].$run['stderr']);
        self::assertStringContainsString('bin/check-assets: node 24.20.0, npm 11.19.0', $run['stdout']);
        self::assertStringContainsString('the build reproduces the committed dist/', $run['stdout']);
        self::assertSame(['--version', 'ci --engine-strict --no-audit --no-fund', 'run build'], $this->npmCalls());
    }

    /**
     * @return iterable<string, array{0: array<string, string>, 1: string}>
     */
    public static function buildsThatDiffer(): iterable
    {
        yield 'a changed file' => [['ORRERY_FAKE_BUILD' => 'built differently'], ' M dist/app.js'];
        yield 'a new file' => [['ORRERY_FAKE_EXTRA' => 'chunk.js'], '?? dist/chunk.js'];
    }

    /**
     * @param  array<string, string>  $env
     */
    #[DataProvider('buildsThatDiffer')]
    public function test_a_build_that_differs_from_the_committed_dist_fails(array $env, string $status): void
    {
        $run = $this->checkAssets($env);

        self::assertSame(1, $run['exit'], $run['stdout']);
        self::assertStringContainsString($status, $run['stderr']);
        self::assertStringContainsString('the build does not reproduce the committed dist/', $run['stderr']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function evalInTheBundle(): iterable
    {
        yield 'Function(' => ['x=Function("r","regeneratorRuntime = r")(t);'];
        yield 'new Function (' => ['var f=new Function ("data","return data");'];
        yield 'eval(' => ['eval("1+1");'];
        yield 'eval after a dot' => ['window.eval(code);'];
        yield 'an indirect eval' => ['(0, eval)(code);'];
        yield 'an indirect eval with a space' => ['(0,eval)(code);'];
        yield 'eval by a string index' => ['window["eval"](code);'];
        yield 'Function by a string index' => ["globalThis['Function']('return 1')();"];
        yield 'setTimeout with a string' => ['setTimeout("tick()", 5);'];
        yield 'setInterval with a string' => ["setInterval ( 'tick()', 5);"];
        yield 'a constructor with a string' => ['[].map.constructor("return this")();'];
    }

    /**
     * Rejects: a check that misses an injected `Function(` or `eval(` in
     * dist/app.js (the policy has no 'unsafe-eval'), and a check
     * that runs only on a build that differs from dist/ (here the build
     * reproduces the committed file).
     * Still passes: an eval reached without a call in the source text, for
     * example `window["ev" + "al"](code)`.
     */
    #[DataProvider('evalInTheBundle')]
    public function test_a_bundle_that_calls_eval_or_function_fails(string $code): void
    {
        $this->commitBundle($code);

        $run = $this->checkAssets(['ORRERY_FAKE_BUILD' => $code]);

        self::assertSame(1, $run['exit'], $run['stdout'].$run['stderr']);
        self::assertStringContainsString('dist/app.js calls eval() or Function()', $run['stderr']);
        self::assertStringNotContainsString('the build reproduces the committed dist/', $run['stdout']);
    }

    /**
     * Rejects: a check that matches a name that only ends in "eval" or
     * "Function" (`retrieval(`, `isFunction(`) or a word without a call.
     */
    public function test_a_bundle_with_similar_names_passes(): void
    {
        $code = 'retrieval(x);isFunction(y);typeof x=="function";a.evaluate(b);var Functions=[];setTimeout(() => f(), 5);setInterval(tick, 5);x.constructor(y);a["evaluate"];';
        $this->commitBundle($code);

        $run = $this->checkAssets(['ORRERY_FAKE_BUILD' => $code]);

        self::assertSame(0, $run['exit'], $run['stdout'].$run['stderr']);
        self::assertStringContainsString('the build reproduces the committed dist/', $run['stdout']);
    }

    /**
     * Rejects: a check that reads a grep error (exit 2: the file is missing or
     * cannot be read) as "no match" and passes.
     */
    public function test_an_unreadable_bundle_fails(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root reads a file without read permission');
        }

        $run = $this->checkAssets(['ORRERY_FAKE_UNREADABLE_APP' => '1']);
        chmod($this->repo.'/dist/app.js', 0644);

        self::assertSame(1, $run['exit'], $run['stdout'].$run['stderr']);
        self::assertStringContainsString('could not read dist/app.js', $run['stderr']);
    }

    /**
     * Rejects: a check without the bundle budget (Orrery::js()
     * inlines dist/app.js into every page, so the file must not grow
     * unnoticed), a budget that is one byte smaller than it says (the case
     * at the budget passes), and a check that compares the size before the
     * build (both cases commit the same bundle, so only the built file
     * counts).
     */
    #[DataProvider('bundleSizes')]
    public function test_the_bundle_budget_holds_the_built_app(int $delta, int $exit): void
    {
        $bytes = self::bundleBudgetBytes() + $delta;
        $this->commitBundleOfSize($bytes);

        $run = $this->checkAssets(['ORRERY_FAKE_BUILD_BYTES' => (string) $bytes]);

        self::assertSame($exit, $run['exit'], $run['stdout'].$run['stderr']);
        if ($exit === 0) {
            self::assertStringContainsString('the build reproduces the committed dist/', $run['stdout']);
        } else {
            self::assertStringContainsString("dist/app.js has {$bytes} bytes, above the bundle budget of ".self::bundleBudgetBytes().' bytes', $run['stderr']);
            self::assertStringNotContainsString('the build reproduces the committed dist/', $run['stdout']);
        }
    }

    /**
     * @return iterable<string, array{0: int, 1: int}>
     */
    public static function bundleSizes(): iterable
    {
        yield 'at the budget' => [0, 0];
        yield 'one byte above the budget' => [1, 1];
    }

    /**
     * Rejects: a budget in bin/check-assets that differs from Vite's
     * chunkSizeWarningLimit (in kB of 1,000), so that the build warns below
     * the budget, and a committed bundle above the budget.
     * Still passes: a bundle whose non-ASCII characters put its bytes above
     * the budget while Vite (which counts UTF-16 code units) stays silent;
     * the byte check is the authority there.
     */
    public function test_the_budget_equals_the_vite_warning_limit_and_holds_the_committed_bundle(): void
    {
        $vite = (string) file_get_contents(self::repoRoot().'/vite.config.js');
        self::assertSame(1, preg_match('/^\s*chunkSizeWarningLimit:\s*(\d+),/m', $vite, $limit), 'vite.config.js sets no chunkSizeWarningLimit.');
        self::assertSame(self::bundleBudgetBytes(), (int) $limit[1] * 1000);

        $size = filesize(self::repoRoot().'/dist/app.js');
        self::assertIsInt($size);
        self::assertLessThanOrEqual(self::bundleBudgetBytes(), $size, 'The committed dist/app.js is above the bundle budget.');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function stylesInTheBundle(): iterable
    {
        // Vue's static stringifier hoists a large static block, a constant :style included, as one HTML string.
        yield 'a double-quoted static block' => ['const n=Pn("<div class=\\"a\\" style=\\"color:red\\">x</div>",1);'];
        yield 'a template literal block' => ['const n=Pn(`<div class="a" style="width:2px">x</div>`,1);'];
        yield 'a single-quoted block' => ["const n=Pn('<span\tstyle=\"top:0\">x</span>',1);"];
    }

    /**
     * Rejects: a check that lets a literal `style="…"` attribute into
     * dist/app.js (Vue inserts a stringified static block through innerHTML,
     * and the enforced policy blocks its inline style), in a
     * double-quoted, template or single-quoted string.
     * Still passes: a style attribute assembled at run time from parts
     * (`"sty" + "le=\""`), which the build never emits for a template.
     */
    #[DataProvider('stylesInTheBundle')]
    public function test_a_bundle_with_a_literal_style_attribute_fails(string $code): void
    {
        $this->commitBundle($code);

        $run = $this->checkAssets(['ORRERY_FAKE_BUILD' => $code]);

        self::assertSame(1, $run['exit'], $run['stdout'].$run['stderr']);
        self::assertStringContainsString('dist/app.js holds a literal style attribute', $run['stderr']);
        self::assertStringNotContainsString('the build reproduces the committed dist/', $run['stdout']);
    }

    /**
     * Rejects: a check that also refuses the CSSOM forms that the policy
     * allows (`el.style=x`, `t.style={…}`, `style:` in an object, a
     * `data-style` attribute).
     */
    public function test_a_bundle_with_cssom_styles_passes(): void
    {
        $code = 't.style=ze(u);el.style.width="2px";const o={style:u};el.style="color:red";h("div",{"data-style":"x"});';
        $this->commitBundle($code);

        $run = $this->checkAssets(['ORRERY_FAKE_BUILD' => $code]);

        self::assertSame(0, $run['exit'], $run['stdout'].$run['stderr']);
    }

    private static function bundleBudgetBytes(): int
    {
        $script = (string) file_get_contents(self::repoRoot().'/bin/check-assets');
        self::assertSame(1, preg_match('/^bundle_budget_bytes=(\d+)$/m', $script, $match), 'bin/check-assets has no bundle_budget_bytes line.');

        return (int) $match[1];
    }

    private function commitBundleOfSize(int $bytes): void
    {
        file_put_contents($this->repo.'/dist/app.js', str_repeat('a', $bytes));
        $this->git(['add', 'dist/app.js']);
        $this->commit('bundle');
    }

    private function commitBundle(string $code): void
    {
        file_put_contents($this->repo.'/dist/app.js', $code."\n");
        $this->git(['add', 'dist/app.js']);
        $this->commit('bundle');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function changesBeforeTheBuild(): iterable
    {
        yield 'a modified file' => ['modified'];
        yield 'a staged file' => ['staged'];
        yield 'an untracked file' => ['untracked'];
    }

    #[DataProvider('changesBeforeTheBuild')]
    public function test_uncommitted_changes_before_the_build_are_refused(string $change): void
    {
        if ($change === 'untracked') {
            file_put_contents($this->repo.'/dist/leftover.js', "old\n");
        } else {
            file_put_contents($this->repo.'/dist/app.js', "edited\n");
        }

        if ($change === 'staged') {
            $this->git(['add', 'dist/app.js']);
        }

        $run = $this->checkAssets();

        self::assertSame(1, $run['exit'], $run['stdout']);
        self::assertStringContainsString('has uncommitted changes before the build', $run['stderr']);
        self::assertSame([], $this->npmCalls(), 'npm ran before the refusal');
    }

    public function test_a_lock_file_that_is_not_committed_is_refused(): void
    {
        $this->git(['rm', '--cached', '--quiet', 'package-lock.json']);
        $this->commit('drop the lock');

        $run = $this->checkAssets();

        self::assertSame(1, $run['exit'], $run['stdout']);
        self::assertStringContainsString('package-lock.json is not committed', $run['stderr']);
    }

    public function test_a_failed_npm_ci_fails_before_the_build(): void
    {
        $run = $this->checkAssets(['ORRERY_FAKE_NPM_CI_EXIT' => '1']);

        self::assertSame(1, $run['exit'], $run['stdout']);
        self::assertStringContainsString('npm ci failed: package-lock.json does not match package.json', $run['stderr']);
        self::assertNotContains('run build', $this->npmCalls());
    }

    public function test_another_node_major_than_nvmrc_is_refused(): void
    {
        $run = $this->checkAssets(['ORRERY_FAKE_NODE' => '26.5.0']);

        self::assertSame(1, $run['exit'], $run['stdout']);
        self::assertStringContainsString('node 26.5.0 is not Node 24 (.nvmrc)', $run['stderr']);
        self::assertSame([], $this->npmCalls());
    }

    public function test_the_repository_declares_the_same_node_major_in_nvmrc_and_engines(): void
    {
        $nvmrc = trim((string) file_get_contents(self::repoRoot().'/.nvmrc'));
        $engines = json_decode((string) file_get_contents(self::repoRoot().'/package.json'), true)['engines'];

        self::assertMatchesRegularExpression('/^\d+$/', $nvmrc);
        self::assertSame('^'.$nvmrc.'.0.0', $engines['node']);
        self::assertArrayHasKey('npm', $engines);
        self::assertSame($engines, json_decode((string) file_get_contents(self::repoRoot().'/package-lock.json'), true)['packages']['']['engines']);
    }

    /**
     * @param  array<string, string>  $env
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function checkAssets(array $env = []): array
    {
        $run = $this->runProcess([$this->repo.'/bin/check-assets'], $this->scriptEnv(array_merge([
            'PATH' => $this->tools.':'.getenv('PATH'),
            'ORRERY_FAKE_NPM_LOG' => $this->tools.'/npm.log',
        ], $env)), 60.0);

        return ['exit' => $run['exit'], 'stdout' => $run['stdout'], 'stderr' => $run['stderr']];
    }

    /**
     * @return list<string>
     */
    private function npmCalls(): array
    {
        return is_file($this->tools.'/npm.log') ? (file($this->tools.'/npm.log', FILE_IGNORE_NEW_LINES) ?: []) : [];
    }

    /**
     * @param  list<string>  $arguments
     */
    private function git(array $arguments): void
    {
        $git = $this->runProcess(['git', '-C', $this->repo, ...$arguments], $this->scriptEnv(), 30.0);
        self::assertSame(0, $git['exit'], 'git '.implode(' ', $arguments).': '.$git['stderr']);
    }

    private function commit(string $message): void
    {
        $this->git(['-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', '-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=/dev/null', 'commit', '--quiet', '-m', $message]);
    }
}
