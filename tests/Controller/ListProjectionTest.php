<?php

declare(strict_types=1);

namespace Justrau\Orrery\Tests\Controller;

use Illuminate\Database\Events\QueryExecuted;
use Justrau\Orrery\Http\Lists\JobList;
use Justrau\Orrery\Http\Projection\JobProjection;
use Justrau\Orrery\Tests\Controller\Fakes\User;
use Justrau\Orrery\Tests\ControllerTest;
use Justrau\Orrery\Tests\Feature\Lists\ListFixtures;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The projection of the list engine (it replaces the
 * projection test of the legacy lists): every list read
 * selects JobList::SCAN_COLUMNS and then the page's rows with
 * JobProjection::SUMMARY_COLUMNS, never the payload, the exception text or
 * the context. A list row carries the first line of the exception message,
 * cut to JobProjection::MESSAGE_CHARACTERS. The job detail still reads and
 * returns the whole exception.
 */
final class ListProjectionTest extends ControllerTest
{
    use ListFixtures;

    private const BATCH = 'projection-batch';

    private const TAG = 'orders';

    private const TRACE = "RuntimeException: The first line\n#0 /app/Jobs/Sync.php(12): handle()\n#1 {main}";

    private const CONTEXT = '{"note":"context-value"}';

    /**
     * The orrery_jobs columns that no list read selects, with the reason.
     * A new column must be added to SUMMARY_COLUMNS or here.
     */
    private const NOT_LISTED = [
        'payload' => 'the job data: the detail only',
        'exception' => 'the trace: the detail only',
        'context' => "Laravel's Context: the detail only",
    ];

    /**
     * @return array<string, array{0: string}>
     */
    public static function sources(): array
    {
        $sources = [];

        foreach (['pending', 'running', 'completed', 'silenced', 'failed', 'retrying', 'unknown'] as $tab) {
            $sources[$tab] = ['/orrery/api/jobs?status='.$tab];
        }

        return $sources + [
            'failed, queue scoped (merged ranges)' => ['/orrery/api/jobs?status=failed&connection=database&queue=default'],
            'failed, residual filters' => ['/orrery/api/jobs?status=failed&class=App%5CJobs%5CSync&q=Sync'],
            'tag, all' => ['/orrery/api/jobs?status=all&tag='.self::TAG],
            'tag, failed' => ['/orrery/api/jobs?status=failed&tag='.self::TAG],
            'batch members' => ['/orrery/api/jobs?status=failed&batch='.self::BATCH],
            'uuid' => ['/orrery/api/jobs?status=failed&q='.self::uuidOf(5)],
            'older page' => ['cursor'],
            'counts' => ['/orrery/api/counts'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $statuses = ['pending', 'reserved', 'completed', 'failed', 'retrying', 'unknown'];

        for ($n = 1; $n <= 12; $n++) {
            $status = $statuses[$n % count($statuses)];
            $this->addRow($n, $status, $n, [
                'silenced' => $n === 3,
                'batch_id' => self::BATCH,
                'exception' => self::TRACE,
                'exception_bytes' => strlen(self::TRACE),
                'exception_class' => 'RuntimeException',
                'exception_message' => 'The first line',
                'context' => self::CONTEXT,
                'context_bytes' => strlen(self::CONTEXT),
            ]);
            $this->addTag($n, self::TAG, $n, $status === 'failed');
        }

        $this->flushRows();
        $this->mirror()->table('orrery_monitored_tags')->insert(['tag' => self::TAG, 'created_at' => '1790000000.000000']);
    }

    /**
     * Rejects: a list read (any tab, a merged-range or residual scan, a tag
     * or batch source, a uuid lookup, a later page, or the tab counts) that
     * selects the payload, the exception text or the context, or that
     * selects `*`.
     * Still passes: a column read through a raw expression with another name.
     */
    #[DataProvider('sources')]
    public function test_list_reads_select_no_payload_exception_or_context(string $uri): void
    {
        if ($uri === 'cursor') {
            $first = $this->actingAs(new User)->getJson('/orrery/api/jobs?status=failed&per_page=1')->assertOk();
            $cursor = $first->json('next_cursor');
            self::assertIsString($cursor, 'the first page has an older page');
            $uri = '/orrery/api/jobs?status=failed&per_page=1&cursor='.rawurlencode($cursor);
        }

        $statements = [];
        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->actingAs(new User)->getJson($uri)->assertOk();

        $jobs = array_values(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'orrery_jobs')));
        self::assertNotSame([], $jobs, "{$uri} reads orrery_jobs");

        foreach ($jobs as $sql) {
            self::assertDoesNotMatchRegularExpression('/["`\[](payload|exception|context)["`\]]|select \*|\.\*/i', $sql, "{$uri}: {$sql}");
        }
    }

    /**
     * Rejects: a summary projection that names a column the table does not
     * have, one that names the payload, the exception or the context, and a
     * new orrery_jobs column that is neither listed nor named in NOT_LISTED
     * (a list would then miss it without a decision).
     * Still passes: a listed column that the row builder then drops.
     */
    public function test_the_summary_columns_are_every_listed_column_of_the_table(): void
    {
        $columns = $this->mirrorSchema()->getColumnListing('orrery_jobs');

        self::assertSame([], array_values(array_diff(JobProjection::SUMMARY_COLUMNS, $columns)), 'a summary column the table does not have');
        self::assertSame([], array_values(array_diff(JobList::SCAN_COLUMNS, $columns)), 'a scan column the table does not have');
        self::assertSame([], array_values(array_intersect(array_keys(self::NOT_LISTED), array_merge(JobProjection::SUMMARY_COLUMNS, JobList::SCAN_COLUMNS))));
        self::assertSame([], array_values(array_diff(array_keys(self::NOT_LISTED), $columns)), 'NOT_LISTED names a column the table does not have');
    }

    /**
     * Rejects: a list row that carries the trace, the context or the
     * payload, a message that is not cut to its first line, and a cut at
     * another length than MESSAGE_CHARACTERS.
     */
    public function test_a_failed_list_row_carries_the_first_line_of_the_message_only(): void
    {
        $this->mirror()->table('orrery_jobs')->where('uuid', self::uuidOf(9))
            ->update(['exception_message' => str_repeat('e', JobProjection::MESSAGE_CHARACTERS + 100)."\nsecond line"]);
        $this->mirror()->table('orrery_jobs')->where('uuid', self::uuidOf(3))
            ->update(['exception_message' => "The first line\nthe second line"]);

        $rows = collect($this->actingAs(new User)->getJson('/orrery/api/jobs?status=failed')->assertOk()->json('jobs'))->keyBy('id');
        $row = $rows->get(self::uuidOf(9));
        $other = $rows->get(self::uuidOf(3));

        self::assertIsArray($row);
        self::assertIsArray($other);
        self::assertSame(str_repeat('e', JobProjection::MESSAGE_CHARACTERS), $row['exception_message']);
        self::assertSame('The first line', $other['exception_message']);

        foreach (['payload', 'exception', 'context'] as $field) {
            self::assertArrayNotHasKey($field, $row);
        }

        self::assertStringNotContainsString('the second line', (string) json_encode($rows->all()));
        self::assertStringNotContainsString('context-value', (string) json_encode($rows->all()));
        self::assertStringNotContainsString('Sync.php(12)', (string) json_encode($rows->all(), JSON_UNESCAPED_SLASHES));
    }

    /**
     * Rejects: a job detail that reads the list projection (it would lose
     * the trace and the context).
     */
    public function test_the_job_detail_keeps_the_exception_and_the_context(): void
    {
        $detail = $this->actingAs(new User)->getJson('/orrery/api/jobs/'.self::uuidOf(9))->assertOk();

        self::assertSame(self::TRACE, $detail->json('exception'));
        self::assertSame(['note' => 'context-value'], $detail->json('context'));
    }
}
