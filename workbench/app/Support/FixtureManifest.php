<?php

namespace Workbench\App\Support;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Reads the expected-count table of workbench/fixtures.md and checks it against a database.
 *
 * workbench/check.php and WorkbenchSeederTest both use this class, so fixtures.md is the one
 * place that states the expected counts. The class has no Laravel dependency on purpose.
 */
final class FixtureManifest
{
    public const BEGIN = '<!-- manifest:begin -->';

    public const END = '<!-- manifest:end -->';

    private const ROW = '/^\|\s*(?<label>[^|`]+?)\s*\|\s*`(?<query>[^`]+)`\s*\|\s*(?<expected>\d+)\s*\|$/';

    /**
     * @param  list<array{label: string, query: string, expected: int}>  $checks
     */
    private function __construct(public readonly array $checks)
    {
    }

    public static function path(): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'fixtures.md';
    }

    /**
     * @throws RuntimeException When the file or its manifest table is missing or malformed.
     */
    public static function load(?string $path = null): self
    {
        $path ??= self::path();
        $text = @file_get_contents($path);

        if ($text === false) {
            throw new RuntimeException(sprintf('Cannot read the fixture manifest [%s].', $path));
        }

        $begin = strpos($text, self::BEGIN);
        $end = $begin === false ? false : strpos($text, self::END, $begin);

        if ($begin === false || $end === false) {
            throw new RuntimeException(sprintf('The fixture manifest [%s] has no %s ... %s block.', $path, self::BEGIN, self::END));
        }

        $block = substr($text, $begin + strlen(self::BEGIN), $end - $begin - strlen(self::BEGIN));
        $checks = [];

        foreach (preg_split('/\R/', $block) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || preg_match('/^\|\s*Check\s*\|/', $line) === 1 || preg_match('/^\|[\s|:-]+\|$/', $line) === 1) {
                continue;
            }

            if (preg_match(self::ROW, $line, $match) !== 1) {
                throw new RuntimeException(sprintf('Malformed fixture manifest row in [%s]: %s', $path, $line));
            }

            $query = trim($match['query']);

            if (preg_match('/^SELECT\s/i', $query) !== 1 || str_contains($query, ';')) {
                throw new RuntimeException(sprintf('A fixture manifest query must be one SELECT statement: %s', $query));
            }

            $checks[] = [
                'label' => $match['label'],
                'query' => $query,
                'expected' => (int) $match['expected'],
            ];
        }

        if ($checks === []) {
            throw new RuntimeException(sprintf('The fixture manifest [%s] has no checks.', $path));
        }

        return new self($checks);
    }

    /**
     * Run every check against the database.
     *
     * @return list<string> One message per check that does not hold; empty when all hold.
     */
    public function verify(PDO $pdo): array
    {
        $failures = [];

        foreach ($this->checks as $check) {
            try {
                $statement = $pdo->query($check['query']);
                $value = $statement === false ? false : $statement->fetchColumn();
            } catch (PDOException $exception) {
                $failures[] = sprintf('%s: the query failed: %s', $check['label'], $exception->getMessage());

                continue;
            }

            if (! is_numeric($value) || (int) $value !== $check['expected'] || (float) $value !== (float) (int) $value) {
                $failures[] = sprintf('%s: expected %d, found %s', $check['label'], $check['expected'], var_export($value, true));
            }
        }

        return $failures;
    }
}
