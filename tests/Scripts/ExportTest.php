<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Scripts;

use Justrau\Orrery\Tests\Scripts\Concerns\RunsScripts;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The package export: `git archive` holds exactly the shipped
 * paths, and `.gitattributes` export-ignores everything else.
 *
 * The test builds its own git repository in a temporary directory, with the
 * checkout as the work tree, and archives that. So it also runs in a matrix
 * leg's copy, which has no `.git`. In a git checkout the tree is the list of
 * `git ls-files --cached --others --exclude-standard`; in a copy without
 * `.git` it is every file that `.gitignore` does not ignore.
 *
 * Every top-level path of the tree must be in SHIPPED or IGNORED, so a new
 * top-level file needs a decision.
 *
 * It is in the group scripts: `composer test` skips it, `composer test:all`
 * runs it.
 */
#[Group('scripts')]
final class ExportTest extends TestCase
{
    use RunsScripts;

    /** The top-level paths that the package ships. */
    private const SHIPPED = [
        'CHANGELOG.md', 'LICENSE', 'NOTICE.md', 'README.md', 'composer.json',
        'config', 'database', 'dist', 'resources', 'routes', 'src', 'stubs',
    ];

    /** The top-level paths that only the development of the package uses. */
    private const IGNORED = [
        '.gitattributes', '.github', '.gitignore', '.npmrc', '.nvmrc',
        'CLAUDE.md', 'CONTRIBUTING.md', 'SECURITY.md', 'bin', 'docker', 'docs',
        'package.json', 'phpstan.neon.dist', 'phpunit.xml.dist', 'pint.json', 'pnpm-lock.yaml', 'pnpm-workspace.yaml',
        'testbench.yaml', 'tests', 'tools', 'vite.config.js', 'workbench',
    ];

    /** The agent instruction files, export-ignored at any depth. */
    private const AGENT_FILES = ['CLAUDE.md', 'AGENTS.md'];

    protected function tearDown(): void
    {
        $this->cleanUpScripts();
        parent::tearDown();
    }

    /**
     * Rejects: a missing or partial `.gitattributes` (tests, docs, a dot file
     * or a nested CLAUDE.md in the archive), a shipped path that is
     * export-ignored, and an unclassified top-level path in the checkout.
     */
    public function test_the_archive_holds_exactly_the_shipped_set(): void
    {
        $export = $this->exportOf(self::repoRoot());

        self::assertSame([], self::problems($export['tree'], $export['archive']));
        // The lists are not empty by accident, and the nested rule applies.
        self::assertContains('src/OrreryServiceProvider.php', $export['archive']);
        self::assertContains('dist/app.js', $export['archive']);
        self::assertContains('src/CLAUDE.md', $export['tree']);
        self::assertNotContains('src/CLAUDE.md', $export['archive']);
        self::assertNotContains('resources/js/CLAUDE.md', $export['archive']);
        self::assertContains('resources/views/layout.blade.php', $export['archive']);
    }

    /**
     * Rejects: a `.gitattributes` without `dist/* -diff linguist-generated`.
     */
    public function test_dist_is_marked_generated(): void
    {
        $export = $this->exportOf(self::repoRoot());

        $attributes = $this->git($export['git'], null, ['check-attr', '--cached', 'diff', 'linguist-generated', '--', 'dist/app.js']);

        self::assertSame("dist/app.js: diff: unset\ndist/app.js: linguist-generated: set\n", $attributes);
    }

    /**
     * Rejects: a tree walk that honours no `.gitignore` in a copy without
     * `.git` (vendor/ would count as an unclassified path), and an archive
     * that reads no attributes from the committed `.gitattributes`.
     */
    public function test_a_copy_without_git_is_exported_by_its_gitignore(): void
    {
        $root = $this->makeTempDir('orrery-export-');
        copy(self::repoRoot().'/.gitattributes', $root.'/.gitattributes');
        $files = [
            '.gitignore' => "/vendor/\n/composer.lock\n",
            'composer.json' => "{}\n",
            'src/A.php' => "<?php\n",
            'src/Deep/CLAUDE.md' => "notes\n",
            'src/Deep/AGENTS.md' => "notes\n",
            'tests/ATest.php' => "<?php\n",
            'dist/app.js' => "built\n",
            'vendor/x/y.php' => "<?php\n",
            'composer.lock' => "{}\n",
        ];

        foreach ($files as $path => $content) {
            @mkdir(dirname($root.'/'.$path), 0700, true);
            file_put_contents($root.'/'.$path, $content);
        }

        $export = $this->exportOf($root);

        self::assertSame(['.gitattributes', '.gitignore', 'composer.json', 'dist/app.js', 'src/A.php', 'src/Deep/AGENTS.md', 'src/Deep/CLAUDE.md', 'tests/ATest.php'], $export['tree']);
        self::assertSame(['composer.json', 'dist/app.js', 'src/A.php'], $export['archive']);
        self::assertSame([], self::problems($export['tree'], $export['archive']));
    }

    /**
     * @return iterable<string, array{list<string>, list<string>, string}>
     */
    public static function brokenExports(): iterable
    {
        yield 'an unclassified top-level file' => [['src/A.php', 'NOTES.md'], ['src/A.php', 'NOTES.md'], 'unclassified top-level path: NOTES.md'];
        yield 'an unclassified top-level directory' => [['src/A.php', 'extra/B.php'], ['src/A.php'], 'unclassified top-level path: extra'];
        yield 'a nested agent file in the archive' => [['src/A.php', 'src/X/AGENTS.md'], ['src/A.php', 'src/X/AGENTS.md'], 'exported but not shipped: src/X/AGENTS.md'];
        yield 'a development path in the archive' => [['src/A.php', 'tests/T.php'], ['src/A.php', 'tests/T.php'], 'exported but not shipped: tests/T.php'];
        yield 'a shipped file left out' => [['src/A.php', 'stubs/s.stub'], ['src/A.php'], 'not exported: stubs/s.stub'];
    }

    /**
     * Rejects: a check that compares only the top-level names, or that lets
     * an unknown top-level path or a nested agent file pass.
     *
     * @param  list<string>  $tree
     * @param  list<string>  $archive
     */
    #[DataProvider('brokenExports')]
    public function test_a_broken_export_is_reported(array $tree, array $archive, string $problem): void
    {
        self::assertSame([$problem], self::problems($tree, $archive));
    }

    /**
     * Every difference between the archive and the shipped part of the tree,
     * and every top-level path that neither list names.
     *
     * @param  list<string>  $tree
     * @param  list<string>  $archive
     * @return list<string>
     */
    private static function problems(array $tree, array $archive): array
    {
        $problems = [];
        $expected = [];

        foreach ($tree as $path) {
            $top = explode('/', $path, 2)[0];

            if (! in_array($top, self::SHIPPED, true) && ! in_array($top, self::IGNORED, true)) {
                $problems[] = 'unclassified top-level path: '.$top;
                // Its archive entries are part of the same problem.
                $archive = array_values(array_filter($archive, static fn (string $entry): bool => explode('/', $entry, 2)[0] !== $top));
            } elseif (in_array($top, self::SHIPPED, true) && ! in_array(basename($path), self::AGENT_FILES, true)) {
                $expected[] = $path;
            }
        }

        foreach (array_diff($expected, $archive) as $path) {
            $problems[] = 'not exported: '.$path;
        }

        foreach (array_diff($archive, $expected) as $path) {
            $problems[] = 'exported but not shipped: '.$path;
        }

        return array_values(array_unique($problems));
    }

    /**
     * Commits the tree of $root into a new repository in a temporary
     * directory and archives it.
     *
     * @return array{git: string, tree: list<string>, archive: list<string>}
     */
    private function exportOf(string $root): array
    {
        $git = $this->makeTempDir('orrery-export-git-').'/repo.git';
        $this->git(null, null, ['init', '--quiet', '--bare', $git]);

        if (file_exists($root.'/.git')) {
            $listed = explode("\0", $this->git(null, $root, ['ls-files', '-z', '--cached', '--others', '--exclude-standard']));
            $existing = array_filter($listed, static fn (string $path): bool => $path !== '' && is_file($root.'/'.$path));
            $list = dirname($git).'/paths';
            file_put_contents($list, implode("\0", $existing));
            $this->git($git, $root, ['add', '--force', '--pathspec-from-file='.$list, '--pathspec-file-nul']);
        } else {
            $this->git($git, $root, ['add', '--all']);
        }

        $this->git($git, $root, ['-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', '-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=/dev/null', 'commit', '--quiet', '--no-verify', '-m', 'export']);

        $tar = dirname($git).'/export.tar';
        $this->git($git, null, ['archive', '--format=tar', '--output='.$tar, 'HEAD']);
        $listing = $this->runProcess(['tar', '-tf', $tar], $this->gitEnv());
        self::assertSame(0, $listing['exit'], 'tar -tf: '.$listing['stderr']);

        return [
            'git' => $git,
            'tree' => self::lines($this->git($git, null, ['ls-files', '-z']), "\0"),
            'archive' => array_values(array_filter(self::lines($listing['stdout'], "\n"), static fn (string $path): bool => ! str_ends_with($path, '/'))),
        ];
    }

    /**
     * @param  list<string>  $arguments
     */
    private function git(?string $gitDir, ?string $workTree, array $arguments): string
    {
        $command = ['git'];

        if ($gitDir !== null) {
            $command[] = '--git-dir='.$gitDir;
        }

        if ($workTree !== null) {
            $command = [...$command, ...($gitDir === null ? ['-C', $workTree] : ['--work-tree='.$workTree])];
        }

        $run = $this->runProcess([...$command, ...$arguments], $this->gitEnv(), 60.0);
        self::assertSame(0, $run['exit'], implode(' ', [...$command, ...$arguments]).': '.$run['stderr']);

        return $run['stdout'];
    }

    /** @return array<string, string|false> */
    private function gitEnv(): array
    {
        return $this->scriptEnv(['GIT_DIR' => false, 'GIT_WORK_TREE' => false, 'GIT_INDEX_FILE' => false]);
    }

    /** @return list<string> sorted, without empty entries */
    private static function lines(string $text, string $separator): array
    {
        $lines = array_values(array_filter(explode($separator, $text), static fn (string $line): bool => $line !== ''));
        sort($lines, SORT_STRING);

        return $lines;
    }
}
